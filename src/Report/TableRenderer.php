<?php

declare(strict_types=1);

namespace PhpAiGuard\Report;

use PhpAiGuard\Support\Str;

/**
 * Renders report rows as an aligned text table or CSV.
 */
final class TableRenderer
{
    /**
     * @param list<array<string, int|float|string>> $rows
     */
    public static function text(array $rows, string $groupLabel): string
    {
        $headers = [$groupLabel, 'Requests', 'Input tok', 'Output tok', 'Cached tok', 'Cache hits', 'Errors', 'Avg ms', 'Cost USD'];
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = self::cells($row);
        }
        $totals = Reporter::totals($rows);
        $lines[] = ['TOTAL', (string) $totals['requests'], number_format($totals['input_tokens']), number_format($totals['output_tokens']), '', (string) $totals['cache_hits'], (string) $totals['errors'], '', self::money($totals['cost_usd'])];

        $widths = array_map(static fn (string $h): int => Str::length($h), $headers);
        foreach ($lines as $line) {
            foreach ($line as $i => $cell) {
                $widths[$i] = max($widths[$i], Str::length($cell));
            }
        }
        $separator = '+' . implode('+', array_map(static fn (int $w): string => str_repeat('-', $w + 2), $widths)) . '+';
        $format = static function (array $cells) use ($widths): string {
            $out = [];
            foreach ($cells as $i => $cell) {
                $pad = $widths[$i] - Str::length($cell);
                $out[] = ' ' . ($i === 0 ? $cell . str_repeat(' ', $pad) : str_repeat(' ', $pad) . $cell) . ' ';
            }

            return '|' . implode('|', $out) . '|';
        };

        $output = [$separator, $format($headers), $separator];
        foreach ($lines as $i => $line) {
            if ($i === count($lines) - 1) {
                $output[] = $separator;
            }
            $output[] = $format($line);
        }
        $output[] = $separator;

        return implode(PHP_EOL, $output) . PHP_EOL;
    }

    /**
     * @param list<array<string, int|float|string>> $rows
     */
    public static function csv(array $rows, string $groupLabel): string
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, [$groupLabel, 'requests', 'input_tokens', 'output_tokens', 'cached_tokens', 'cache_hits', 'errors', 'avg_latency_ms', 'cost_usd'], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, [
                self::csvSafe((string) $row['group']),
                $row['requests'],
                $row['input_tokens'],
                $row['output_tokens'],
                $row['cached_tokens'],
                $row['cache_hits'],
                $row['errors'],
                $row['avg_latency_ms'],
                sprintf('%.6f', (int) $row['cost_micros'] / 1_000_000),
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public static function money(float $usd): string
    {
        return '$' . number_format($usd, $usd > 0 && $usd < 1 ? 4 : 2);
    }

    /**
     * @param array<string, int|float|string> $row
     *
     * @return list<string>
     */
    private static function cells(array $row): array
    {
        return [
            Str::limit((string) $row['group'], 40),
            number_format((int) $row['requests']),
            number_format((int) $row['input_tokens']),
            number_format((int) $row['output_tokens']),
            number_format((int) $row['cached_tokens']),
            number_format((int) $row['cache_hits']),
            number_format((int) $row['errors']),
            number_format((int) $row['avg_latency_ms']),
            self::money((int) $row['cost_micros'] / 1_000_000),
        ];
    }

    /** Stops spreadsheet formula injection from user-controlled ids. */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
