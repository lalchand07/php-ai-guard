<?php

declare(strict_types=1);

namespace PhpAiGuard\Retry;

/**
 * Does not sleep; remembers the requested delays. For tests.
 */
final class RecordingSleeper implements SleeperInterface
{
    /** @var list<float> */
    public array $sleeps = [];

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
