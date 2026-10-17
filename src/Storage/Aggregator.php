<?php

declare(strict_types=1);

namespace PhpAiGuard\Storage;

use PhpAiGuard\Exception\ConfigurationException;

/**
 * Groups usage records in PHP (used by the memory and file stores).
 *
 * @internal
 */
final class Aggregator
{
    /**
     * @param iterable<UsageRecord> $records
     *
     * @return list<array{group: string, requests: int, input_tokens: int, output_tokens: int, cached_tokens: int, cost_micros: int, cache_hits: int, errors: int, avg_latency_ms: int}>
     */
    public static function aggregate(iterable $records, int $from, int $to, string $groupBy): array
    {
        self::assertGroupBy($groupBy);
        $groups = [];
        $latency = [];
        foreach ($records as $record) {
            if ($record->createdAt < $from || $record->createdAt >= $to) {
                continue;
            }
            $key = self::groupValue($record, $groupBy);
            $groups[$key] ??= ['group' => $key, 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cached_tokens' => 0, 'cost_micros' => 0, 'cache_hits' => 0, 'errors' => 0, 'avg_latency_ms' => 0];
            $latency[$key] ??= 0;
            $groups[$key]['requests']++;
            $groups[$key]['input_tokens'] += $record->inputTokens;
            $groups[$key]['output_tokens'] += $record->outputTokens;
            $groups[$key]['cached_tokens'] += $record->cachedTokens;
            $groups[$key]['cost_micros'] += $record->costMicros;
            $groups[$key]['cache_hits'] += $record->cacheHit ? 1 : 0;
            $groups[$key]['errors'] += in_array($record->status, [UsageRecord::STATUS_OK], true) ? 0 : 1;
            $latency[$key] += $record->latencyMs;
        }
        foreach ($groups as $key => $row) {
            $groups[$key]['avg_latency_ms'] = (int) round($latency[$key] / max(1, $row['requests']));
        }

        return self::sort(array_values($groups));
    }

    /**
     * @param list<array{group: string, requests: int, input_tokens: int, output_tokens: int, cached_tokens: int, cost_micros: int, cache_hits: int, errors: int, avg_latency_ms: int}> $rows
     *
     * @return list<array{group: string, requests: int, input_tokens: int, output_tokens: int, cached_tokens: int, cost_micros: int, cache_hits: int, errors: int, avg_latency_ms: int}>
     */
    public static function sort(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$b['cost_micros'], $b['requests'], $a['group']] <=> [$a['cost_micros'], $a['requests'], $b['group']]);

        return $rows;
    }

    public static function assertGroupBy(string $groupBy): void
    {
        if (!in_array($groupBy, StorageInterface::GROUP_BY, true)) {
            throw new ConfigurationException(sprintf('Cannot group usage by "%s". Use one of: %s.', $groupBy, implode(', ', StorageInterface::GROUP_BY)));
        }
    }

    private static function groupValue(UsageRecord $record, string $groupBy): string
    {
        return match ($groupBy) {
            'tenant' => $record->tenant ?? '(none)',
            'user' => $record->user ?? '(none)',
            'feature' => $record->feature ?? '(none)',
            'model' => $record->provider . ':' . $record->model,
            'provider' => $record->provider,
            'day' => gmdate('Y-m-d', $record->createdAt),
            default => $record->status,
        };
    }
}
