<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Budget;

use PhpAiGuard\Budget\BudgetManager;
use PhpAiGuard\Budget\BudgetPolicy;
use PhpAiGuard\Budget\Windows;
use PhpAiGuard\Context;
use PhpAiGuard\Exception\BudgetExceededException;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Tests\TestCase;

final class BudgetManagerTest extends TestCase
{
    private function manager(array $budgets, string $timezone = 'UTC'): BudgetManager
    {
        return new BudgetManager($this->storage, new BudgetPolicy($budgets), $this->clock, new Windows(new \DateTimeZone($timezone)));
    }

    public function testRefusesExactlyAtTheBoundary(): void
    {
        $budgets = $this->manager(['user' => ['monthly' => 1.00]]);
        $user = new Context(null, '42');

        $budgets->settle($budgets->reserve($user, 0.40), 0.40);
        $budgets->settle($budgets->reserve($user, 0.40), 0.40);
        // Exactly the remaining $0.20 is allowed...
        $last = $budgets->reserve($user, 0.20);
        self::assertSame(0.0, $budgets->remaining('user', '42', 'monthly'));

        // ...but one more micro-dollar is not.
        try {
            $budgets->reserve($user, 0.000001);
            self::fail('Expected BudgetExceededException');
        } catch (BudgetExceededException $e) {
            self::assertSame('user', $e->getScope());
            self::assertSame('42', $e->getScopeId());
            self::assertSame('monthly', $e->getWindow());
            self::assertSame(1.0, $e->getLimit());
            self::assertEqualsWithDelta(1.0, $e->getSpent(), 1e-9);
            self::assertSame('2026-11-01', $e->getResetsAt()?->format('Y-m-d'));
            self::assertStringContainsString('monthly budget for the user "42"', $e->getMessage());
            self::assertSame('You have reached your AI usage limit for this month. It resets on 1 November 2026.', $e->userMessage());
        }

        // Settling below the estimate gives the difference back.
        $budgets->settle($last, 0.05);
        self::assertEqualsWithDelta(0.15, (float) $budgets->remaining('user', '42', 'monthly'), 1e-9);
    }

    public function testAllScopesAreCheckedAtomically(): void
    {
        $budgets = $this->manager([
            'global' => ['monthly' => 10.00],
            'tenant' => ['monthly' => 1.00],
            'user' => ['daily' => 0.50],
        ]);

        $budgets->reserve(new Context('acme', 'u1'), 0.50);
        try {
            // u2's own budget is fine, but tenant acme only has $0.50 left.
            $budgets->reserve(new Context('acme', 'u2'), 0.60);
            self::fail('Expected BudgetExceededException');
        } catch (BudgetExceededException $e) {
            self::assertSame('tenant', $e->getScope());
        }
        // Nothing was charged to the global or user counters by the refused call.
        self::assertSame(0.5, $budgets->spent('global', null, 'monthly'));
        self::assertSame(0.0, $budgets->spent('user', 'u2', 'daily'));
    }

    public function testOverridesAndDailyReset(): void
    {
        $budgets = $this->manager([
            'user' => ['daily' => 0.10],
            'users' => ['vip' => ['daily' => 5.00]],
        ]);

        $budgets->reserve(new Context(null, 'vip'), 1.00);
        $budgets->reserve(new Context(null, 'normal'), 0.10);
        try {
            $budgets->reserve(new Context(null, 'normal'), 0.01);
            self::fail('Expected BudgetExceededException');
        } catch (BudgetExceededException $e) {
            self::assertSame('daily', $e->getWindow());
            self::assertSame('You have reached your AI usage limit for today. It resets on 18 October 2026.', $e->userMessage());
        }

        $this->clock->advance(86400);
        $budgets->reserve(new Context(null, 'normal'), 0.10);
        self::assertSame(0.0, $budgets->remaining('user', 'normal', 'daily'));
    }

    public function testWindowsFollowTheConfiguredTimezone(): void
    {
        // 23:30 UTC on 31 October is already 1 November in Tokyo.
        $this->clock->set('2026-10-31 23:30:00');
        $budgets = $this->manager(['global' => ['monthly' => 1.00]], 'Asia/Tokyo');
        $budgets->reserve(new Context(), 1.00);

        $this->clock->set('2026-10-31 14:00:00'); // still October in Tokyo
        self::assertSame(1.0, $budgets->remaining('global', null, 'monthly'));
    }

    public function testReleaseAndFreeCalls(): void
    {
        $budgets = $this->manager(['global' => ['daily' => 1.00]]);
        $reservation = $budgets->reserve(new Context(), 0.75);
        $budgets->release($reservation);
        $budgets->release($reservation); // idempotent
        self::assertSame(1.0, $budgets->remaining('global', null, 'daily'));

        $budgets->reserve(new Context(), 1.00);
        // A free model (estimate 0) is never blocked.
        self::assertSame([], $budgets->reserve(new Context(), 0.0)->keys);
    }

    public function testInvalidConfig(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('weekly');
        new BudgetPolicy(['user' => ['weekly' => 1]]);
    }
}
