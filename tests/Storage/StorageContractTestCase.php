<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Storage;

use PhpAiGuard\Storage\CounterOperation;
use PhpAiGuard\Storage\StorageInterface;
use PhpAiGuard\Storage\UsageRecord;
use PHPUnit\Framework\TestCase;

/**
 * The same behaviour is required from every storage backend.
 */
abstract class StorageContractTestCase extends TestCase
{
    abstract protected function createStorage(): StorageInterface;

    public function testReserveIsAllOrNothing(): void
    {
        $storage = $this->createStorage();
        self::assertNull($storage->reserve([new CounterOperation('a', 5, 10, 2000000000), new CounterOperation('b', 5, 10, 2000000000)]));
        self::assertNull($storage->reserve([new CounterOperation('a', 5, 10, 2000000000)]));

        // "a" would go to 15 > 10, so "b" must not be touched either.
        self::assertSame('a', $storage->reserve([new CounterOperation('b', 1, 10, 2000000000), new CounterOperation('a', 5, 10, 2000000000)]));
        self::assertSame(10, $storage->getCounter('a'));
        self::assertSame(5, $storage->getCounter('b'));

        // No limit = always allowed.
        self::assertNull($storage->reserve([new CounterOperation('c', 7, null, 2000000000)]));
        self::assertSame(7, $storage->getCounter('c'));
        self::assertSame(0, $storage->getCounter('missing'));
    }

    public function testAdjustNeverGoesNegative(): void
    {
        $storage = $this->createStorage();
        $storage->reserve([new CounterOperation('a', 100, 1000, 2000000000)]);
        $storage->adjust(['a' => -30]);
        self::assertSame(70, $storage->getCounter('a'));
        $storage->adjust(['a' => -500, 'unknown' => 5]);
        self::assertSame(0, $storage->getCounter('a'));
        self::assertSame(0, $storage->getCounter('unknown'));
    }

    public function testUsageLogAggregation(): void
    {
        $storage = $this->createStorage();
        $t = 1792152000; // 2026-10-16 UTC
        $storage->logUsage(new UsageRecord($t, 'anthropic', 'claude-haiku-5-5', 'ok', 'acme', 'u1', 'summary', 100, 50, 0, 1500, 300));
        $storage->logUsage(new UsageRecord($t + 60, 'anthropic', 'claude-haiku-5-5', 'ok', 'acme', 'u2', 'summary', 200, 10, 20, 500, 100, true));
        $storage->logUsage(new UsageRecord($t + 120, 'groq', 'llama', 'error', 'globex', 'u3', null, 0, 0, 0, 0, 50, false, 'ProviderUnavailableException:503'));
        $storage->logUsage(new UsageRecord($t - 40 * 86400, 'groq', 'llama', 'ok', 'acme', 'u1', null, 1, 1, 0, 999999)); // previous month

        $byTenant = $storage->aggregateUsage($t - 10 * 86400, $t + 10 * 86400, 'tenant');
        self::assertCount(2, $byTenant);
        self::assertSame('acme', $byTenant[0]['group']);
        self::assertSame(2, $byTenant[0]['requests']);
        self::assertSame(300, $byTenant[0]['input_tokens']);
        self::assertSame(60, $byTenant[0]['output_tokens']);
        self::assertSame(20, $byTenant[0]['cached_tokens']);
        self::assertSame(2000, $byTenant[0]['cost_micros']);
        self::assertSame(1, $byTenant[0]['cache_hits']);
        self::assertSame(0, $byTenant[0]['errors']);
        self::assertSame(200, $byTenant[0]['avg_latency_ms']);
        self::assertSame('globex', $byTenant[1]['group']);
        self::assertSame(1, $byTenant[1]['errors']);

        $byModel = $storage->aggregateUsage($t - 10 * 86400, $t + 10 * 86400, 'model');
        self::assertSame(['anthropic:claude-haiku-5-5', 'groq:llama'], array_column($byModel, 'group'));

        $byFeature = $storage->aggregateUsage($t - 10 * 86400, $t + 10 * 86400, 'feature');
        self::assertSame(['summary', '(none)'], array_column($byFeature, 'group'));

        $byDay = $storage->aggregateUsage($t - 10 * 86400, $t + 10 * 86400, 'day');
        self::assertSame('2026-10-16', $byDay[0]['group']);
    }

    public function testPrune(): void
    {
        $storage = $this->createStorage();
        $storage->reserve([new CounterOperation('old', 1, null, 1000), new CounterOperation('new', 1, null, 3000)]);
        self::assertSame(1, $storage->prune(2000));
        self::assertSame(0, $storage->getCounter('old'));
        self::assertSame(1, $storage->getCounter('new'));
    }
}
