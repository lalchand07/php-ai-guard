<?php

declare(strict_types=1);

namespace PhpAiGuard\Retry;

interface SleeperInterface
{
    public function sleep(float $seconds): void;
}
