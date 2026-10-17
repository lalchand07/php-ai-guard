<?php

declare(strict_types=1);

namespace PhpAiGuard\Storage;

/**
 * Keeps everything in PHP memory. Only lives as long as the PHP process: use
 * it in tests and long-running workers, never for budgets on a web server.
 */
final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, array{amount: int, expires_at: int}> */
    private array $counters = [];

    /** @var list<UsageRecord> */
    private array $records = [];

    public function reserve(array $operations): ?string
    {
        foreach ($operations as $op) {
            $current = $this->counters[$op->key]['amount'] ?? 0;
            if ($op->limit !== null && $current + $op->amount > $op->limit) {
                return $op->key;
            }
        }
        foreach ($operations as $op) {
            $this->counters[$op->key] = [
                'amount' => ($this->counters[$op->key]['amount'] ?? 0) + $op->amount,
                'expires_at' => max($op->expiresAt, $this->counters[$op->key]['expires_at'] ?? 0),
            ];
        }

        return null;
    }

    public function adjust(array $deltas): void
    {
        foreach ($deltas as $key => $delta) {
            if (isset($this->counters[$key])) {
                $this->counters[$key]['amount'] = max(0, $this->counters[$key]['amount'] + $delta);
            }
        }
    }

    public function getCounter(string $key): int
    {
        return $this->counters[$key]['amount'] ?? 0;
    }

    public function logUsage(UsageRecord $record): void
    {
        $this->records[] = $record;
    }

    public function aggregateUsage(int $from, int $to, string $groupBy): array
    {
        return Aggregator::aggregate($this->records, $from, $to, $groupBy);
    }

    public function prune(int $now): int
    {
        $before = count($this->counters);
        $this->counters = array_filter($this->counters, static fn (array $c): bool => $c['expires_at'] >= $now);

        return $before - count($this->counters);
    }

    /** @return list<UsageRecord> */
    public function records(): array
    {
        return $this->records;
    }
}
