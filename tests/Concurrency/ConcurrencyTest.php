<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Concurrency;

use PhpAiGuard\Storage\FileStorage;
use PhpAiGuard\Storage\PdoStorage;
use PhpAiGuard\Storage\StorageInterface;
use PhpAiGuard\Tests\Storage\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Several real PHP processes compete for the same budget. However the
 * requests interleave, the number of granted reservations must equal the
 * limit exactly: never more (overspend) and never fewer (lost updates).
 *
 * @group concurrency
 */
final class ConcurrencyTest extends TestCase
{
    private const WORKERS = 6;
    private const ATTEMPTS = 40;
    private const LIMIT = 100; // < WORKERS * ATTEMPTS, so most workers hit the limit

    /**
     * @requires extension pdo_sqlite
     */
    public function testSqliteCountersAreSafeUnderConcurrentRequests(): void
    {
        $path = TempDir::create() . '/budget.sqlite';
        PdoStorage::sqlite($path); // install tables once
        $granted = $this->runWorkers('sqlite', $path);

        self::assertSame(self::LIMIT, $granted, 'granted reservations must equal the limit exactly');
        $storage = PdoStorage::sqlite($path, 'ai_guard_', false);
        $this->assertCounters($storage);
    }

    /**
     * @requires extension pdo_mysql
     * @group mysql
     */
    public function testMysqlCountersAreSafeUnderConcurrentRequests(): void
    {
        $storage = \PhpAiGuard\Tests\Storage\MysqlStorageTest::connect();
        $storage->install();
        $storage->pdo()->exec('DELETE FROM ai_guard_counters');
        $granted = $this->runWorkers('mysql', '');

        self::assertSame(self::LIMIT, $granted);
        $this->assertCounters($storage);
    }

    public function testFileCountersAreSafeUnderConcurrentRequests(): void
    {
        $path = TempDir::create() . '/store';
        new FileStorage($path);
        $granted = $this->runWorkers('file', $path);

        self::assertSame(self::LIMIT, $granted);
        $this->assertCounters(new FileStorage($path));
    }

    private function assertCounters(StorageInterface $storage): void
    {
        self::assertSame(self::LIMIT, $storage->getCounter('budget:user:42:monthly:2026-10'));
        // The second counter in each operation moved in lock-step: all-or-nothing.
        self::assertSame(self::LIMIT, $storage->getCounter('budget:global:-:monthly:2026-10'));
    }

    private function runWorkers(string $driver, string $path): int
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled.');
        }
        $processes = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $command = [PHP_BINARY, __DIR__ . '/worker.php', $driver, $path, (string) self::ATTEMPTS, (string) self::LIMIT];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $granted = 0;
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            self::assertSame(0, $code, 'worker failed: ' . $err . $out);
            self::assertMatchesRegularExpression('/^\d+$/', (string) $out, 'worker output: ' . $out . $err);
            $granted += (int) $out;
        }

        return $granted;
    }
}
