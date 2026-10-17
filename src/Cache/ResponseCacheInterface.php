<?php

declare(strict_types=1);

namespace PhpAiGuard\Cache;

/**
 * Stores provider responses so identical requests are paid for once.
 */
interface ResponseCacheInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value, int $ttl): void;
}
