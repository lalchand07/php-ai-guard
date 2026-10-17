<?php

declare(strict_types=1);

namespace PhpAiGuard\Cache;

final class NullCache implements ResponseCacheInterface
{
    public function get(string $key): ?array
    {
        return null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
    }
}
