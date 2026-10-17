<?php

declare(strict_types=1);

namespace PhpAiGuard\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Adapter for any PSR-16 cache (Redis, Memcached, APCu, framework caches, ...).
 */
final class Psr16Cache implements ResponseCacheInterface
{
    public function __construct(private CacheInterface $cache, private string $prefix = 'ai_guard_')
    {
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get($this->prefix . hash('sha256', $key));

        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        $this->cache->set($this->prefix . hash('sha256', $key), $value, $ttl);
    }
}
