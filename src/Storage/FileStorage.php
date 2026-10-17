<?php

declare(strict_types=1);

namespace PhpAiGuard\Storage;

use PhpAiGuard\Exception\StorageException;

/**
 * Plain files for tiny sites without a database: counters in one JSON file
 * guarded by an exclusive flock(), usage appended to one JSONL file per month.
 *
 * Keep the directory outside the web root (or deny access to it).
 */
final class FileStorage implements StorageInterface
{
    private string $dir;

    public function __construct(string $directory)
    {
        $this->dir = rtrim($directory, '/\\');
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new StorageException(sprintf('Cannot create storage directory "%s".', $this->dir));
        }
        if (!is_writable($this->dir)) {
            throw new StorageException(sprintf('Storage directory "%s" is not writable.', $this->dir));
        }
        $htaccess = $this->dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
    }

    public function reserve(array $operations): ?string
    {
        return $this->withCounters(static function (array &$counters) use ($operations): ?string {
            foreach ($operations as $op) {
                $current = $counters[$op->key]['amount'] ?? 0;
                if ($op->limit !== null && $current + $op->amount > $op->limit) {
                    return $op->key;
                }
            }
            foreach ($operations as $op) {
                $counters[$op->key] = [
                    'amount' => ($counters[$op->key]['amount'] ?? 0) + $op->amount,
                    'expires_at' => max($op->expiresAt, $counters[$op->key]['expires_at'] ?? 0),
                ];
            }

            return null;
        });
    }

    public function adjust(array $deltas): void
    {
        $this->withCounters(static function (array &$counters) use ($deltas): ?string {
            foreach ($deltas as $key => $delta) {
                if (isset($counters[$key])) {
                    $counters[$key]['amount'] = max(0, $counters[$key]['amount'] + $delta);
                }
            }

            return null;
        });
    }

    public function getCounter(string $key): int
    {
        $counters = $this->readCounters();

        return $counters[$key]['amount'] ?? 0;
    }

    public function logUsage(UsageRecord $record): void
    {
        $file = $this->dir . '/usage-' . gmdate('Y-m', $record->createdAt) . '.jsonl';
        $line = json_encode($record->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new StorageException(sprintf('Cannot write usage log "%s".', $file));
        }
    }

    public function aggregateUsage(int $from, int $to, string $groupBy): array
    {
        Aggregator::assertGroupBy($groupBy);

        return Aggregator::aggregate($this->records($from, $to), $from, $to, $groupBy);
    }

    public function prune(int $now): int
    {
        $removed = 0;
        $this->withCounters(static function (array &$counters) use ($now, &$removed): ?string {
            foreach ($counters as $key => $counter) {
                if ($counter['expires_at'] < $now) {
                    unset($counters[$key]);
                    $removed++;
                }
            }

            return null;
        });

        return $removed;
    }

    /**
     * @return \Generator<int, UsageRecord>
     */
    private function records(int $from, int $to): \Generator
    {
        $month = (new \DateTimeImmutable('@' . $from))->modify('first day of this month')->setTime(0, 0);
        $end = new \DateTimeImmutable('@' . max($from, $to - 1));
        while ($month <= $end) {
            $file = $this->dir . '/usage-' . $month->format('Y-m') . '.jsonl';
            $month = $month->modify('+1 month');
            if (!is_file($file)) {
                continue;
            }
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                throw new StorageException(sprintf('Cannot read usage log "%s".', $file));
            }
            flock($handle, LOCK_SH);
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    yield UsageRecord::fromArray($row);
                }
            }
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array{amount: int, expires_at: int}>
     */
    private function readCounters(): array
    {
        $file = $this->dir . '/counters.json';
        if (!is_file($file)) {
            return [];
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new StorageException(sprintf('Cannot read "%s".', $file));
        }
        flock($handle, LOCK_SH);
        $json = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return self::decode((string) $json);
    }

    /**
     * Runs $callback with the counters under an exclusive lock and writes them back.
     *
     * @param callable(array<string, array{amount: int, expires_at: int}>&): ?string $callback
     */
    private function withCounters(callable $callback): ?string
    {
        $file = $this->dir . '/counters.json';
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            throw new StorageException(sprintf('Cannot open "%s" for writing.', $file));
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new StorageException(sprintf('Cannot lock "%s".', $file));
            }
            $counters = self::decode((string) stream_get_contents($handle));
            $result = $callback($counters);
            if ($result === null) {
                $json = (string) json_encode($counters, JSON_UNESCAPED_SLASHES);
                ftruncate($handle, 0);
                rewind($handle);
                if (fwrite($handle, $json) !== strlen($json)) {
                    throw new StorageException(sprintf('Could not write "%s" (disk full?).', $file));
                }
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array{amount: int, expires_at: int}>
     */
    private static function decode(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new StorageException('The counters file is corrupted (invalid JSON).');
        }
        $counters = [];
        foreach ($data as $key => $counter) {
            if (is_array($counter)) {
                $counters[(string) $key] = ['amount' => (int) ($counter['amount'] ?? 0), 'expires_at' => (int) ($counter['expires_at'] ?? 0)];
            }
        }

        return $counters;
    }
}
