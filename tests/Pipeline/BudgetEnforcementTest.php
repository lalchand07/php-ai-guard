<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Pipeline;

use PhpAiGuard\Exception\BudgetExceededException;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\PricingUnknownException;
use PhpAiGuard\Exception\RateLimitExceededException;
use PhpAiGuard\Tests\TestCase;

final class BudgetEnforcementTest extends TestCase
{
    public function testRefusesBeforeSendingWhenWorstCaseExceedsBudget(): void
    {
        // Pricing: $2 per 1M output tokens. max_tokens 1000 → output alone can cost $0.002.
        $ai = $this->guard(['budgets' => ['user' => ['monthly' => 0.001]]]);

        try {
            $ai->forUser('42')->chat('Hello', ['max_tokens' => 1000]);
            self::fail('Expected BudgetExceededException');
        } catch (BudgetExceededException $e) {
            self::assertGreaterThan(0.002, $e->getEstimate());
            self::assertSame('user', $e->getScope());
        }
        self::assertSame([], $this->http->requests, 'nothing may be sent when the budget could be exceeded');
        self::assertSame('budget_refused', $this->storage->records()[0]->status);
    }

    public function testSettlesToActualCostAndBlocksOnceSpent(): void
    {
        $ai = $this->guard(['budgets' => ['user' => ['monthly' => 0.01]]])->forUser('42');

        // Actual usage: 1000 in ($0.001) + 1000 out ($0.002) = $0.003 per call.
        $this->http->queue(self::anthropicText('one', 1000, 1000))->queue(self::anthropicText('two', 1000, 1000))->queue(self::anthropicText('three', 1000, 1000));
        $first = $ai->chat('Hi', ['max_tokens' => 1000]);
        self::assertEqualsWithDelta(0.003, $first->cost, 1e-9);
        self::assertEqualsWithDelta(0.007, (float) $ai->remainingBudget(), 1e-9);

        $ai->chat('Hi', ['max_tokens' => 1000]);
        $ai->chat('Hi', ['max_tokens' => 1000]);
        self::assertEqualsWithDelta(0.001, (float) $ai->remainingBudget(), 1e-9);

        // $0.001 left but the worst case of the next call is > $0.002.
        $this->expectException(BudgetExceededException::class);
        $ai->chat('Hi', ['max_tokens' => 1000]);
    }

    public function testBudgetFallbackTriesCheaperTargets(): void
    {
        $ai = $this->guard([
            'budget_fallback' => true,
            'budgets' => ['global' => ['daily' => 0.001]],
            'pricing' => [
                'anthropic' => ['claude-haiku-5-5' => ['input' => 100.0, 'output' => 100.0]],
                'gemini' => ['gemini-3.8-flash' => ['input' => 100.0, 'output' => 100.0]],
                'openai' => ['gpt-test' => ['free' => true]],
            ],
        ]);
        $this->http->queue(self::openAiText('free answer'));

        self::assertSame('free answer', $ai->chat('Hi')->text);
        self::assertSame(['api.openai.com'], $this->requestedHosts());
    }

    public function testUnknownPricingIsRefusedByDefault(): void
    {
        $ai = $this->guard();
        try {
            $ai->chat('Hi', ['model' => 'anthropic:claude-unknown']);
            self::fail('Expected PricingUnknownException');
        } catch (PricingUnknownException $e) {
            self::assertStringContainsString('anthropic:claude-unknown', $e->getMessage());
        }
        self::assertSame([], $this->http->requests);

        $this->http->queue(self::anthropicText('ok'));
        $response = $this->guard(['unknown_pricing' => 'allow'])->chat('Hi', ['model' => 'anthropic:claude-unknown']);
        self::assertSame(0.0, $response->cost);
    }

    public function testRateLimitIsCheckedFirstAndLogged(): void
    {
        $ai = $this->guard(['rate_limits' => ['user' => 1]])->forUser('7', 'acme');
        $this->http->queue(self::anthropicText('ok'));
        $ai->chat('Hi');

        try {
            $ai->chat('Hi again');
            self::fail('Expected RateLimitExceededException');
        } catch (RateLimitExceededException $e) {
            self::assertSame('7', $e->getScopeId());
        }
        self::assertCount(1, $this->http->requests);
        $last = $this->storage->records()[1];
        self::assertSame('rate_limited', $last->status);
        self::assertSame('acme', $last->tenant);
    }

    public function testBudgetsRequirePersistentStorage(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('persistent storage');
        $this->guard(['storage' => null, 'budgets' => ['global' => ['monthly' => 1]]]);
    }

    public function testPromptCachingIsBudgetedAtCacheWritePrice(): void
    {
        // 30 000 bytes → 10 000 + 24 overhead estimated tokens; anthropic input $1.00, cache write $1.25 per 1M.
        // Worst case without caching ≈ $0.01003, with caching ≈ $0.01253; the limit sits in between.
        $ai = $this->guard(['budgets' => ['global' => ['daily' => 0.011]]]);
        $prompt = str_repeat('a', 30000);
        $this->http->queue(self::anthropicText('ok'));

        $ai->chat($prompt, ['max_tokens' => 1, 'model' => 'anthropic:claude-haiku-5-5']);

        $this->expectException(BudgetExceededException::class);
        $ai->chat($prompt, ['max_tokens' => 1, 'model' => 'anthropic:claude-haiku-5-5', 'prompt_cache' => true]);
    }
}
