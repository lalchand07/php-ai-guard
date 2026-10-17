<?php

declare(strict_types=1);

namespace PhpAiGuard\Storage;

use PhpAiGuard\Exception\StorageException;

/**
 * Persistence for budget/rate-limit counters and the usage log.
 *
 * Implementations must make reserve() atomic: either every operation is
 * applied or none is, even with concurrent requests.
 */
interface StorageInterface
{
    public const GROUP_BY = ['tenant', 'user', 'feature', 'model', 'provider', 'day', 'status'];

    /**
     * Atomically applies all operations if every counter stays within its limit.
     *
     * @param list<CounterOperation> $operations
     *
     * @return string|null null on success, otherwise the key of the first counter that would exceed its limit
     *
     * @throws StorageException
     */
    public function reserve(array $operations): ?string;

    /**
     * Adds (possibly negative) deltas to existing counters; results never go below zero.
     *
     * @param array<string, int> $deltas key => delta
     */
    public function adjust(array $deltas): void;

    public function getCounter(string $key): int;

    public function logUsage(UsageRecord $record): void;

    /**
     * Aggregates the usage log for created_at in [$from, $to).
     *
     * @param string $groupBy one of self::GROUP_BY
     *
     * @return list<array{group: string, requests: int, input_tokens: int, output_tokens: int, cached_tokens: int, cost_micros: int, cache_hits: int, errors: int, avg_latency_ms: int}>
     */
    public function aggregateUsage(int $from, int $to, string $groupBy): array;

    /**
     * Deletes expired counters. Returns the number removed.
     */
    public function prune(int $now): int;
}
