<?php

declare(strict_types=1);

namespace PhpAiGuard\Budget;

/**
 * Money held against budget counters while a call is in flight.
 */
final class Reservation
{
    private bool $closed = false;

    /**
     * @param list<string> $keys counters the amount was added to
     */
    public function __construct(public array $keys, public int $micros)
    {
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
