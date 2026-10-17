<?php

declare(strict_types=1);

namespace PhpAiGuard\Cache;

use PhpAiGuard\Clock\ClockInterface;
use PhpAiGuard\Clock\SystemClock;
use PhpAiGuard\Exception\StorageException;

/**
 * One JSON file per cached response. Keep the directory outside the web root.
 */
final class FileCache implements ResponseCacheInterface
{
    private string $dir;
    private ClockInterface $clock;

    public function __construct(string $directory, ?ClockInterface $clock = null)
    {
        $this->dir = rtrim($directory, '/\\');
        $this->clock = $clock ?? new SystemClock();
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new StorageException(sprintf('Cannot create cache directory "%s".', $this->dir));
        }
        if (!file_exists($this->dir . '/.htaccess')) {
            @file_put_contents($this->dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }

    public function get(string $key): ?array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !isset($data['expires'], $data['value']) || !is_array($data['value'])) {
            return null;
        }
        if ((int) $data['expires'] < $this->clock->now()->getTimestamp()) {
            @unlink($file);

            return null;
        }

        return $data['value'];
    }

    public function set(string $key, array $value, int $ttl): void
    {
        $json = json_encode(['expires' => $this->clock->now()->getTimestamp() + $ttl, 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $file = $this->path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /** Deletes expired entries; returns how many were removed. */
    public function prune(): int
    {
        $removed = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (!is_array($data) || (int) ($data['expires'] ?? 0) < $this->clock->now()->getTimestamp()) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
