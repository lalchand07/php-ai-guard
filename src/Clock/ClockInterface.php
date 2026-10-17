<?php

declare(strict_types=1);

namespace PhpAiGuard\Clock;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
