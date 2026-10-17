<?php

declare(strict_types=1);

namespace PhpAiGuard\Report;

use PhpAiGuard\Budget\Windows;
use PhpAiGuard\Storage\StorageInterface;

/**
 * Usage summaries from the usage log.
 */
final class Reporter
{
    private Windows $windows;

    public function __construct(private StorageInterface $storage, string $timezone = 'UTC')
    {
        $this->windows = new Windows(new \DateTimeZone($timezone));
    }

    /**
     * @param string $month   "YYYY-MM"
     * @param string $groupBy tenant, user, feature, model, provider, day or status
     *
     * @return list<array{group: string, requests: int, input_tokens: int, output_tokens: int, cached_tokens: int, cost_micros: int, cost_usd: float, cache_hits: int, errors: int, avg_latency_ms: int}>
     */
    public function month(string $month, string $groupBy = 'model'): array
    {
        [$from, $to] = $this->windows->monthRange($month);

        return $this->between($from, $to, $groupBy);
    }

    /**
     * @return list<array{group: string, requests: int, input_tokens: int, output_tokens: int, cached_tokens: int, cost_micros: int, cost_usd: float, cache_hits: int, errors: int, avg_latency_ms: int}>
     */
    public function between(int $from, int $to, string $groupBy = 'model'): array
    {
        $rows = [];
        foreach ($this->storage->aggregateUsage($from, $to, $groupBy) as $row) {
            $row['cost_usd'] = $row['cost_micros'] / 1_000_000;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, int|float|string>> $rows
     *
     * @return array{requests: int, input_tokens: int, output_tokens: int, cost_usd: float, cache_hits: int, errors: int}
     */
    public static function totals(array $rows): array
    {
        $totals = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0, 'cache_hits' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            $totals['requests'] += (int) ($row['requests'] ?? 0);
            $totals['input_tokens'] += (int) ($row['input_tokens'] ?? 0);
            $totals['output_tokens'] += (int) ($row['output_tokens'] ?? 0);
            $totals['cost_usd'] += (int) ($row['cost_micros'] ?? 0) / 1_000_000;
            $totals['cache_hits'] += (int) ($row['cache_hits'] ?? 0);
            $totals['errors'] += (int) ($row['errors'] ?? 0);
        }

        return $totals;
    }
}
