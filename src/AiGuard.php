<?php

declare(strict_types=1);

namespace PhpAiGuard;

use PhpAiGuard\Exception\AiGuardException;
use PhpAiGuard\Exception\BudgetExceededException;
use PhpAiGuard\Exception\JsonValidationException;
use PhpAiGuard\Exception\RateLimitExceededException;
use PhpAiGuard\Json\JsonExtractor;
use PhpAiGuard\Json\SchemaValidator;
use PhpAiGuard\Report\Reporter;
use PhpAiGuard\Storage\StorageInterface;

/**
 * The entry point.
 *
 *     $ai = AiGuard::create(require 'ai-guard.php');
 *     echo $ai->forUser('42')->chat('Write a haiku about PHP')->text;
 */
final class AiGuard
{
    private Context $context;

    public function __construct(private Engine $engine, ?Context $context = null)
    {
        $this->context = $context ?? new Context();
    }

    /**
     * Builds an instance from a config array (see README and config/ai-guard.example.php).
     *
     * @param array<string, mixed> $config
     */
    public static function create(array $config): self
    {
        return AiGuardFactory::create($config);
    }

    /**
     * A copy scoped to a user (and optionally their tenant/company) for budgets,
     * rate limits and reports.
     */
    public function forUser(string $userId, ?string $tenantId = null): self
    {
        return new self($this->engine, $this->context->with($tenantId, $userId));
    }

    public function forTenant(string $tenantId): self
    {
        return new self($this->engine, $this->context->with($tenantId));
    }

    /** A copy that tags calls with a feature name ("summary", "classify") for reports and routing. */
    public function withFeature(string $feature): self
    {
        return new self($this->engine, $this->context->with(null, null, $feature));
    }

    public function context(): Context
    {
        return $this->context;
    }

    /**
     * Sends a prompt and returns the answer.
     *
     * @param string|list<Message|array{role: string, content: string}> $prompt  a prompt, or a conversation
     * @param array<string, mixed>                                       $options system, max_tokens, temperature, tier, model,
     *                                                                            user, tenant, feature, cache, cache_ttl, prompt_cache
     *
     * @throws BudgetExceededException    the call could exceed a budget (nothing was sent)
     * @throws RateLimitExceededException too many requests for this user/tenant
     * @throws AiGuardException           any other library error
     */
    public function chat(string|array $prompt, array $options = []): Response
    {
        return $this->engine->send(Request::fromOptions($prompt, $options, $this->context));
    }

    /**
     * Sends a fully built request.
     */
    public function send(Request $request): Response
    {
        if ($request->context->tenant === null && $request->context->user === null && $request->context->feature === null) {
            $request->context = $this->context;
        }

        return $this->engine->send($request);
    }

    /**
     * Asks for JSON matching $schema and returns it as a validated PHP array.
     *
     * If the answer is invalid, the model is asked once more with the
     * validation errors; if it is still invalid a JsonValidationException is
     * thrown.
     *
     * @param string|list<Message|array{role: string, content: string}> $prompt
     * @param array<string, mixed>                                       $schema  JSON-schema subset (see SchemaValidator)
     * @param array<string, mixed>                                       $options as for chat()
     *
     * @return array<mixed>
     *
     * @throws JsonValidationException
     */
    public function json(string|array $prompt, array $schema, array $options = []): array
    {
        return (array) $this->jsonResponse($prompt, $schema, $options)->json;
    }

    /**
     * Like json(), but returns the whole Response (with ->json set) so you can
     * read cost, tokens and model. Cost and usage cover both attempts.
     *
     * @param string|list<Message|array{role: string, content: string}> $prompt
     * @param array<string, mixed>                                       $schema
     * @param array<string, mixed>                                       $options
     */
    public function jsonResponse(string|array $prompt, array $schema, array $options = []): Response
    {
        $request = Request::fromOptions($prompt, ['schema' => $schema] + $options, $this->context);
        $instruction = "Respond with a single JSON value that matches this JSON schema. Output only the JSON: no markdown fences, no explanations.\nJSON schema:\n"
            . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $request->system = $request->system === null || $request->system === '' ? $instruction : $request->system . "\n\n" . $instruction;

        $validator = new SchemaValidator();
        $response = $this->engine->send($request);
        $errors = $this->check($response, $schema, $validator);
        if ($errors === []) {
            return $response;
        }

        // One corrective retry: show the model its own answer and what was wrong.
        $retry = $request->withMessages(array_merge($request->messages, [
            Message::assistant($response->text === '' ? '(empty response)' : $response->text),
            Message::user("That response is not valid. Problems:\n- " . implode("\n- ", array_slice($errors, 0, 20)) . "\nReturn only the corrected JSON."),
        ]));
        $retry->cache = false;
        $second = $this->engine->send($retry, false);
        $secondErrors = $this->check($second, $schema, $validator);

        $second->usage = $response->usage->add($second->usage);
        $second->cost += $response->cost;
        $second->attempts = array_merge($response->attempts, $second->attempts);
        if ($secondErrors !== []) {
            throw new JsonValidationException($secondErrors, $second->text);
        }

        return $second;
    }

    /**
     * Remaining USD in a budget window, or null when it has no limit.
     *
     * @param string      $scope  "global", "tenant" or "user"
     * @param string|null $id     tenant/user id; defaults to the current context
     * @param string      $window "daily" or "monthly"
     */
    public function remainingBudget(string $scope = 'user', ?string $id = null, string $window = 'monthly'): ?float
    {
        if ($scope !== 'global') {
            $id ??= $scope === 'tenant' ? $this->context->tenant : $this->context->user;
        }

        return $this->engine->budgets()->remaining($scope, $scope === 'global' ? null : $id, $window);
    }

    public function storage(): StorageInterface
    {
        return $this->engine->storage();
    }

    public function reporter(): Reporter
    {
        return new Reporter($this->engine->storage(), $this->engine->options()->timezone);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function check(Response $response, array $schema, SchemaValidator $validator): array
    {
        $extracted = JsonExtractor::extract($response->text);
        if (!$extracted['ok']) {
            return [(string) $extracted['error']];
        }
        $errors = $validator->validate($extracted['value'], $schema);
        if ($errors === []) {
            $response->json = is_array($extracted['value']) ? $extracted['value'] : ['value' => $extracted['value']];
        }

        return $errors;
    }
}
