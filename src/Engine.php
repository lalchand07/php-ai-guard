<?php

declare(strict_types=1);

namespace PhpAiGuard;

use PhpAiGuard\Budget\BudgetManager;
use PhpAiGuard\Budget\RateLimiter;
use PhpAiGuard\Budget\Reservation;
use PhpAiGuard\Budget\TokenEstimator;
use PhpAiGuard\Cache\CacheKey;
use PhpAiGuard\Cache\ResponseCacheInterface;
use PhpAiGuard\Clock\ClockInterface;
use PhpAiGuard\Exception\AllProvidersFailedException;
use PhpAiGuard\Exception\BudgetExceededException;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\PricingUnknownException;
use PhpAiGuard\Exception\PrivacyException;
use PhpAiGuard\Exception\ProviderException;
use PhpAiGuard\Exception\RateLimitExceededException;
use PhpAiGuard\Exception\TransientProviderException;
use PhpAiGuard\Pricing\ModelPrice;
use PhpAiGuard\Pricing\PricingTable;
use PhpAiGuard\Privacy\PrivacyPolicy;
use PhpAiGuard\Privacy\RedactionMap;
use PhpAiGuard\Provider\ProviderInterface;
use PhpAiGuard\Provider\ProviderResult;
use PhpAiGuard\Retry\RetryPolicy;
use PhpAiGuard\Retry\SleeperInterface;
use PhpAiGuard\Routing\Router;
use PhpAiGuard\Routing\Target;
use PhpAiGuard\Storage\StorageInterface;
use PhpAiGuard\Storage\UsageRecord;
use Psr\Log\LoggerInterface;

/**
 * The request pipeline: rate limit → route → (privacy → cache → price →
 * reserve budget → call with retries → settle → log) per target.
 *
 * Built by AiGuardFactory; use the AiGuard facade.
 *
 * @internal
 */
final class Engine
{
    /**
     * @param array<string, ProviderInterface> $providers
     */
    public function __construct(
        private array $providers,
        private Router $router,
        private PricingTable $pricing,
        private BudgetManager $budgets,
        private RateLimiter $rateLimiter,
        private StorageInterface $storage,
        private ResponseCacheInterface $cache,
        private PrivacyPolicy $privacy,
        private RetryPolicy $retry,
        private SleeperInterface $sleeper,
        private ClockInterface $clock,
        private TokenEstimator $estimator,
        private EngineOptions $options,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function budgets(): BudgetManager
    {
        return $this->budgets;
    }

    public function storage(): StorageInterface
    {
        return $this->storage;
    }

    public function options(): EngineOptions
    {
        return $this->options;
    }

    public function send(Request $request, bool $countRateLimit = true): Response
    {
        if ($request->maxTokens === null) {
            $request->maxTokens = $this->options->defaultMaxTokens;
        }
        if ($request->maxTokens < 1) {
            throw new ConfigurationException('max_tokens must be at least 1.');
        }
        if ($request->temperature === null) {
            $request->temperature = $this->options->defaultTemperature;
        }
        $context = $request->context;

        if ($countRateLimit) {
            try {
                $this->rateLimiter->hit($context);
            } catch (RateLimitExceededException $e) {
                $this->log(new UsageRecord($this->now(), '-', '-', UsageRecord::STATUS_RATE_LIMITED, $context->tenant, $context->user, $context->feature, errorType: 'rate_limit:' . $e->getScope()));
                $this->logger?->notice('AI request rate limited for {scope} {id}', ['scope' => $e->getScope(), 'id' => $e->getScopeId()]);
                throw $e;
            }
        }

        $targets = $this->router->resolve($request);
        $attempts = [];
        $lastError = null;
        $budgetError = null;
        $privacySkipped = 0;

        foreach ($targets as $target) {
            $provider = $this->provider($target);

            $prepared = $this->privacy->prepare($request, $provider);
            if ($prepared === null) {
                $privacySkipped++;
                $attempts[] = new Attempt((string) $target, Attempt::PRIVACY_SKIPPED, 'personal data not allowed for this provider');
                $this->log($this->record($target, $request, UsageRecord::STATUS_PRIVACY_SKIPPED));
                continue;
            }
            [$outgoing, $map] = $prepared;

            $useCache = $request->cache ?? $this->options->cacheByDefault;
            $cacheKey = $useCache ? CacheKey::for($target, $outgoing) : null;
            if ($cacheKey !== null) {
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null) {
                    $attempts[] = new Attempt((string) $target, Attempt::CACHE_HIT);

                    return $this->fromCache($target, $outgoing, $map, $cached, $attempts);
                }
            }

            $price = $this->price($target);
            $estimate = $price->maxCost($this->estimator->estimate($outgoing), (int) $outgoing->maxTokens, $provider->usesPromptCache($outgoing));
            try {
                $reservation = $this->budgets->reserve($context, $estimate);
            } catch (BudgetExceededException $e) {
                $attempts[] = new Attempt((string) $target, Attempt::BUDGET_REFUSED, $e->getMessage());
                $this->log($this->record($target, $request, UsageRecord::STATUS_BUDGET_REFUSED, errorType: 'budget:' . $e->getScope() . ':' . $e->getWindow()));
                $this->logger?->notice('AI request refused by budget: {message}', ['message' => $e->getMessage()]);
                if (!$this->options->budgetFallback) {
                    throw $e;
                }
                $budgetError ??= $e;
                continue;
            }

            try {
                $result = $this->callWithRetries($target, $provider, $outgoing, $request, $attempts);
            } catch (TransientProviderException $e) {
                // Retries exhausted: release the reservation and fall back to the next target.
                $this->budgets->release($reservation);
                $lastError = $e;
                continue;
            } catch (\Throwable $e) {
                $this->budgets->release($reservation);
                throw $e;
            }

            return $this->complete($target, $provider, $price, $reservation, $request, $outgoing, $map, $result, $cacheKey, $attempts);
        }

        if ($lastError === null && $budgetError !== null) {
            throw $budgetError;
        }
        if ($lastError === null && $privacySkipped > 0) {
            throw new PrivacyException('The request contains personal data and no provider in the chain is allowed to receive it (privacy.on_disallowed = "skip").');
        }

        throw new AllProvidersFailedException($attempts, $lastError);
    }

    /**
     * @param list<Attempt> $attempts
     *
     * @throws ProviderException
     */
    private function callWithRetries(Target $target, ProviderInterface $provider, Request $outgoing, Request $original, array &$attempts): ProviderResult
    {
        for ($retry = 0; ; $retry++) {
            $started = microtime(true);
            try {
                $result = $provider->complete($outgoing, $target->model);
                $result->raw['_latency_ms'] = self::elapsed($started);

                return $result;
            } catch (ProviderException $e) {
                $latency = self::elapsed($started);
                $attempts[] = new Attempt((string) $target, Attempt::ERROR, $e->getMessage(), $latency, $e->getStatusCode());
                $this->log($this->record($target, $original, UsageRecord::STATUS_ERROR, latencyMs: $latency, errorType: self::shortClass($e) . ($e->getStatusCode() !== null ? ':' . $e->getStatusCode() : '')));
                $this->logger?->warning('AI provider {target} failed: {error}', ['target' => (string) $target, 'error' => $e->getMessage(), 'status' => $e->getStatusCode()]);

                if (!$e instanceof TransientProviderException) {
                    // 4xx validation/auth errors: retrying or falling back would fail the same way.
                    throw $e;
                }
                $delay = $this->retry->delay($retry, $e->getRetryAfter());
                if ($delay === null) {
                    throw $e;
                }
                $this->sleeper->sleep($delay);
            }
        }
    }

    /**
     * @param list<Attempt> $attempts
     */
    private function complete(
        Target $target,
        ProviderInterface $provider,
        ModelPrice $price,
        Reservation $reservation,
        Request $request,
        Request $outgoing,
        RedactionMap $map,
        ProviderResult $result,
        ?string $cacheKey,
        array $attempts
    ): Response {
        $cost = $price->cost($result->usage);
        $latency = (int) ($result->raw['_latency_ms'] ?? 0);
        unset($result->raw['_latency_ms']);
        $this->budgets->settle($reservation, $cost);
        $attempts[] = new Attempt((string) $target, Attempt::OK, null, $latency, 200);

        $record = $this->record($target, $request, UsageRecord::STATUS_OK, $result->usage, BudgetManager::toMicros($cost), $latency);
        if ($this->options->logPrompts) {
            // The outgoing (redacted) text is logged, never the original personal data.
            $record->promptText = $outgoing->allText();
            $record->responseText = $result->text;
        }
        $this->log($record);

        if ($cacheKey !== null && $result->text !== '') {
            $this->cache->set($cacheKey, [
                'text' => $result->text,
                'usage' => $result->usage->toArray(),
                'finish_reason' => $result->finishReason,
                'model' => $result->model,
            ], $request->cacheTtl ?? $this->options->cacheTtl);
        }

        $text = $this->privacy->restores() ? $map->restore($result->text) : $result->text;

        return new Response($text, $result->usage, $cost, $latency, $provider->name(), $target->model, false, $result->finishReason, null, $attempts, $result->raw);
    }

    /**
     * @param array<string, mixed> $cached
     * @param list<Attempt>        $attempts
     */
    private function fromCache(Target $target, Request $request, RedactionMap $map, array $cached, array $attempts): Response
    {
        $usage = Usage::fromArray(is_array($cached['usage'] ?? null) ? $cached['usage'] : []);
        $record = $this->record($target, $request, UsageRecord::STATUS_OK);
        $record->cacheHit = true;
        $record->cachedTokens = $usage->inputTokens;
        $this->log($record);

        $text = (string) ($cached['text'] ?? '');
        if ($this->privacy->restores()) {
            $text = $map->restore($text);
        }
        $finish = isset($cached['finish_reason']) && is_string($cached['finish_reason']) ? $cached['finish_reason'] : null;

        return new Response($text, $usage, 0.0, 0, $target->provider, $target->model, true, $finish, null, $attempts);
    }

    private function provider(Target $target): ProviderInterface
    {
        if (!isset($this->providers[$target->provider])) {
            throw new ConfigurationException(sprintf(
                'Unknown provider "%s" in target "%s". Configured providers: %s.',
                $target->provider,
                (string) $target,
                implode(', ', array_keys($this->providers)) ?: 'none'
            ));
        }

        return $this->providers[$target->provider];
    }

    private function price(Target $target): ModelPrice
    {
        $price = $this->pricing->find($target->provider, $target->model);
        if ($price !== null) {
            return $price;
        }
        if (!$this->options->allowUnknownPricing) {
            throw new PricingUnknownException(sprintf(
                'No price configured for "%s", so its cost cannot be checked against your budgets. Add it to "pricing" (USD per 1M tokens) or set "unknown_pricing" => "allow".',
                (string) $target
            ));
        }
        $this->logger?->warning('No price configured for {target}; its cost is recorded as $0.', ['target' => (string) $target]);

        return ModelPrice::free();
    }

    private function record(
        Target $target,
        Request $request,
        string $status,
        ?Usage $usage = null,
        int $costMicros = 0,
        int $latencyMs = 0,
        ?string $errorType = null
    ): UsageRecord {
        $context = $request->context;

        return new UsageRecord(
            $this->now(),
            $target->provider,
            $target->model,
            $status,
            $context->tenant,
            $context->user,
            $context->feature,
            $usage === null ? 0 : $usage->inputTokens,
            $usage === null ? 0 : $usage->outputTokens,
            $usage === null ? 0 : $usage->cacheReadTokens,
            $costMicros,
            $latencyMs,
            false,
            $errorType
        );
    }

    private function log(UsageRecord $record): void
    {
        if (!$this->options->logUsage) {
            return;
        }
        try {
            $this->storage->logUsage($record);
        } catch (\Throwable $e) {
            // A logging failure must not break the user's request (budget counters are separate).
            $this->logger?->error('Could not write AI usage log: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private static function shortClass(object $object): string
    {
        $class = get_class($object);
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
