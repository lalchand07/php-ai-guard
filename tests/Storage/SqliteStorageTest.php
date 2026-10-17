<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Storage;

use PhpAiGuard\Storage\PdoStorage;
use PhpAiGuard\Storage\StorageInterface;
use PhpAiGuard\Storage\UsageRecord;

/**
 * @requires extension pdo_sqlite
 */
final class SqliteStorageTest extends StorageContractTestCase
{
    protected function createStorage(): StorageInterface
    {
        return PdoStorage::sqlite(TempDir::create() . '/ai-guard.sqlite');
    }

    public function testInstallIsIdempotentAndHonoursPrefix(): void
    {
        $path = TempDir::create() . '/db.sqlite';
        $storage = PdoStorage::sqlite($path, 'app_');
        $storage->install();
        $storage->logUsage(new UsageRecord(1792152000, 'p', 'm', 'ok'));

        $tables = $storage->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'app_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['app_counters', 'app_usage'], $tables);
    }

    public function testShippedSqlFileMatchesInstaller(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../sql/sqlite.sql'));
        $storage = new PdoStorage($pdo);
        $storage->logUsage(new UsageRecord(1792152000, 'p', 'm', 'ok', 't', 'u', 'f', 1, 2, 3, 4, 5, true, null, 'prompt', 'response'));
        self::assertSame(1, $storage->aggregateUsage(0, 2000000000, 'model')[0]['requests']);
    }
}
