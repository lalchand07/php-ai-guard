<?php

declare(strict_types=1);

namespace PhpAiGuard\Cache;

use PhpAiGuard\Clock\ClockInterface;
use PhpAiGuard\Clock\SystemClock;

/**
 * In-process cache: tests and long-running workers.
 */
final class ArrayCache implements ResponseCacheInterface
{
    /** @var array<string, array{0: int, 1: array<string, mixed>}> */
    private array $items = [];

    private ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key): ?array
    {
        if (!isset($this->items[$key])) {
            return null;
        }
        [$expires, $value] = $this->items[$key];
        if ($expires < $this->clock->now()->getTimestamp()) {
            unset($this->items[$key]);

            return null;
        }

        return $value;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        $this->items[$key] = [$this->clock->now()->getTimestamp() + $ttl, $value];
    }
}
