<?php

declare(strict_types=1);

namespace PhpAiGuard\Clock;

/**
 * A clock that only moves when told to. Useful in tests.
 */
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string|\DateTimeImmutable $now = 'now')
    {
        $this->now = is_string($now) ? new \DateTimeImmutable($now, new \DateTimeZone('UTC')) : $now;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string|\DateTimeImmutable $now): void
    {
        $this->now = is_string($now) ? new \DateTimeImmutable($now, new \DateTimeZone('UTC')) : $now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
