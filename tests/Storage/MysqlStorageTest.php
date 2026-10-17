<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Storage;

use PhpAiGuard\Storage\PdoStorage;
use PhpAiGuard\Storage\StorageInterface;

/**
 * Runs the storage contract against a real MySQL/MariaDB server when
 * AI_GUARD_TEST_MYSQL_DSN is set (CI starts a MariaDB service), e.g.
 *
 *   AI_GUARD_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=ai_guard" \
 *   AI_GUARD_TEST_MYSQL_USER=root AI_GUARD_TEST_MYSQL_PASSWORD=secret vendor/bin/phpunit
 *
 * @requires extension pdo_mysql
 * @group mysql
 */
final class MysqlStorageTest extends StorageContractTestCase
{
    public static function connect(): PdoStorage
    {
        $dsn = getenv('AI_GUARD_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set AI_GUARD_TEST_MYSQL_DSN to run the MySQL/MariaDB tests.');
        }
        $user = getenv('AI_GUARD_TEST_MYSQL_USER');
        $password = getenv('AI_GUARD_TEST_MYSQL_PASSWORD');

        return PdoStorage::mysql($dsn, is_string($user) ? $user : 'root', is_string($password) ? $password : '');
    }

    protected function createStorage(): StorageInterface
    {
        $storage = self::connect();
        $storage->install();
        $storage->pdo()->exec('DELETE FROM ai_guard_counters');
        $storage->pdo()->exec('DELETE FROM ai_guard_usage');

        return $storage;
    }
}
