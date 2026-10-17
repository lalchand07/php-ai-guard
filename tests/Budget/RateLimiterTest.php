<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Budget;

use PhpAiGuard\Budget\RateLimiter;
use PhpAiGuard\Context;
use PhpAiGuard\Exception\RateLimitExceededException;
use PhpAiGuard\Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testPerUserLimitResetsNextMinute(): void
    {
        $this->clock->set('2026-10-17 12:00:15');
        $limiter = new RateLimiter($this->storage, $this->clock, ['user' => 3, 'tenant' => ['per_minute' => 100]]);
        $alice = new Context('acme', 'alice');

        $limiter->hit($alice);
        $limiter->hit($alice);
        $limiter->hit($alice);
        $limiter->hit(new Context('acme', 'bob')); // other users are unaffected

        try {
            $limiter->hit($alice);
            self::fail('Expected RateLimitExceededException');
        } catch (RateLimitExceededException $e) {
            self::assertSame('user', $e->getScope());
            self::assertSame('alice', $e->getScopeId());
            self::assertSame(3, $e->getLimit());
            self::assertSame(45, $e->getRetryAfterSeconds());
            self::assertStringContainsString('45 seconds', $e->userMessage());
        }

        $this->clock->advance(60);
        $limiter->hit($alice);
        self::assertTrue($limiter->isEnabled());
    }

    public function testTenantLimitStopsCostExhaustionAcrossUsers(): void
    {
        $limiter = new RateLimiter($this->storage, $this->clock, ['tenant' => 2]);
        $limiter->hit(new Context('acme', 'u1'));
        $limiter->hit(new Context('acme', 'u2'));

        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessage('tenant "acme"');
        $limiter->hit(new Context('acme', 'u3'));
    }

    public function testRefusedHitIsNotCounted(): void
    {
        $limiter = new RateLimiter($this->storage, $this->clock, ['user' => 1, 'global' => 2]);
        $limiter->hit(new Context(null, 'a'));
        try {
            $limiter->hit(new Context(null, 'a'));
        } catch (RateLimitExceededException) {
        }
        // The refused request must not have used up the global allowance.
        $limiter->hit(new Context(null, 'b'));
        $this->expectException(RateLimitExceededException::class);
        $limiter->hit(new Context(null, 'c'));
    }
}
