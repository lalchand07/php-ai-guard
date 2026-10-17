<?php

/*
 * Worker process for ConcurrencyTest: hammers reserve() on a shared store and
 * prints how many reservations succeeded.
 *
 * Usage: php worker.php <sqlite|file|mysql> <path> <attempts> <limit>
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PhpAiGuard\Storage\CounterOperation;
use PhpAiGuard\Storage\FileStorage;
use PhpAiGuard\Storage\PdoStorage;

[, $driver, $path, $attempts, $limit] = $argv;
$storage = match ($driver) {
    'sqlite' => PdoStorage::sqlite($path, 'ai_guard_', false),
    'mysql' => PdoStorage::mysql((string) getenv('AI_GUARD_TEST_MYSQL_DSN'), (string) getenv('AI_GUARD_TEST_MYSQL_USER'), (string) getenv('AI_GUARD_TEST_MYSQL_PASSWORD')),
    default => new FileStorage($path),
};

$granted = 0;
for ($i = 0; $i < (int) $attempts; $i++) {
    // Two counters per reservation, like a user + global budget.
    $failed = $storage->reserve([
        new CounterOperation('budget:user:42:monthly:2026-10', 1, (int) $limit, 2000000000),
        new CounterOperation('budget:global:-:monthly:2026-10', 1, (int) $limit * 10, 2000000000),
    ]);
    if ($failed === null) {
        $granted++;
    }
}
echo $granted;
