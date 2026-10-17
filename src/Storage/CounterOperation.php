<?php

declare(strict_types=1);

namespace PhpAiGuard\Storage;

/**
 * "Add $amount to counter $key, but only if the result stays <= $limit."
 */
final class CounterOperation
{
    public function __construct(
        public string $key,
        public int $amount,
        public ?int $limit,
        public int $expiresAt
    ) {
    }
}
