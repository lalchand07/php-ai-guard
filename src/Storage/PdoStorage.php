<?php

declare(strict_types=1);

namespace PhpAiGuard\Storage;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\StorageException;

/**
 * MySQL / MariaDB / SQLite storage through PDO.
 *
 * Budget checks are a conditional UPDATE ("add only if it stays under the
 * limit") per counter inside one transaction, so concurrent PHP requests can
 * never jointly overspend.
 */
final class PdoStorage implements StorageInterface
{
    private string $driver;
    private string $counters;
    private string $usage;

    public function __construct(private \PDO $pdo, private string $tablePrefix = 'ai_guard_')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $tablePrefix)) {
            throw new ConfigurationException('The table prefix may only contain letters, digits and underscores.');
        }
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new ConfigurationException(sprintf('PdoStorage supports the "mysql" and "sqlite" PDO drivers, "%s" given.', $driver));
        }
        $this->driver = $driver;
        $this->counters = $tablePrefix . 'counters';
        $this->usage = $tablePrefix . 'usage';
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if ($driver === 'sqlite') {
            $this->pdo->exec('PRAGMA busy_timeout = 15000');
        }
    }

    /**
     * Opens an SQLite database file (created if missing).
     */
    public static function sqlite(string $path, string $tablePrefix = 'ai_guard_', bool $install = true): self
    {
        $dir = dirname($path);
        if ($path !== ':memory:' && !is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new StorageException(sprintf('Cannot create directory "%s" for the SQLite database.', $dir));
        }
        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_TIMEOUT => 15]);
        } catch (\PDOException $e) {
            throw new StorageException('Cannot open SQLite database: ' . $e->getMessage(), 0, $e);
        }
        $storage = new self($pdo, $tablePrefix);
        if ($install) {
            $storage->install();
        }

        return $storage;
    }

    /**
     * Connects to MySQL / MariaDB.
     */
    public static function mysql(string $dsn, ?string $username, ?string $password, string $tablePrefix = 'ai_guard_'): self
    {
        try {
            $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_TIMEOUT => 10]);
        } catch (\PDOException $e) {
            // The message can contain the user name, never the password.
            throw new StorageException('Cannot connect to the database: ' . $e->getMessage(), 0, $e);
        }

        return new self($pdo, $tablePrefix);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Creates the tables and indexes if they do not exist yet. Safe to run repeatedly.
     */
    public function install(): void
    {
        try {
            foreach (SchemaSql::statements($this->driver, $this->tablePrefix) as $sql) {
                $this->pdo->exec($sql);
            }
        } catch (\PDOException $e) {
            throw new StorageException('Could not create the php-ai-guard tables: ' . $e->getMessage(), 0, $e);
        }
    }

    public function reserve(array $operations): ?string
    {
        usort($operations, static fn (CounterOperation $a, CounterOperation $b): int => strcmp($a->key, $b->key));
        $operations = array_values(array_filter($operations, static fn (CounterOperation $op): bool => $op->amount > 0));
        if ($operations === []) {
            return null;
        }

        for ($try = 1; ; $try++) {
            try {
                return $this->reserveOnce($operations);
            } catch (\PDOException $e) {
                $this->rollback();
                // 40001 = serialization failure / deadlock (MySQL); retry a few times with a short pause.
                if ($try < 3 && ($e->getCode() === '40001' || str_contains($e->getMessage(), 'Deadlock'))) {
                    usleep(random_int(5_000, 50_000));
                    continue;
                }
                throw new StorageException('Could not update budget counters: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * @param list<CounterOperation> $operations sorted, non-empty
     */
    private function reserveOnce(array $operations): ?string
    {
        $this->begin();
        $insert = $this->pdo->prepare($this->driver === 'sqlite'
            ? "INSERT OR IGNORE INTO {$this->counters} (counter_key, amount, expires_at, updated_at) VALUES (?, 0, ?, ?)"
            : "INSERT IGNORE INTO {$this->counters} (counter_key, amount, expires_at, updated_at) VALUES (?, 0, ?, ?)");
        $limited = $this->pdo->prepare("UPDATE {$this->counters} SET amount = amount + ?, updated_at = ? WHERE counter_key = ? AND amount + ? <= ?");
        $unlimited = $this->pdo->prepare("UPDATE {$this->counters} SET amount = amount + ?, updated_at = ? WHERE counter_key = ?");
        $now = time();
        foreach ($operations as $op) {
            self::run($insert, [$op->key, $op->expiresAt, $now]);
            if ($op->limit === null) {
                self::run($unlimited, [$op->amount, $now, $op->key]);
                continue;
            }
            self::run($limited, [$op->amount, $now, $op->key, $op->amount, $op->limit]);
            if ($limited->rowCount() === 0) {
                $this->rollback();

                return $op->key;
            }
        }
        $this->commit();

        return null;
    }

    public function adjust(array $deltas): void
    {
        $deltas = array_filter($deltas, static fn (int $delta): bool => $delta !== 0);
        if ($deltas === []) {
            return;
        }
        ksort($deltas);
        $sql = $this->driver === 'sqlite'
            ? "UPDATE {$this->counters} SET amount = MAX(0, amount + ?), updated_at = ? WHERE counter_key = ?"
            : "UPDATE {$this->counters} SET amount = GREATEST(0, CAST(amount AS SIGNED) + ?), updated_at = ? WHERE counter_key = ?";
        try {
            $this->begin();
            $statement = $this->pdo->prepare($sql);
            foreach ($deltas as $key => $delta) {
                self::run($statement, [$delta, time(), (string) $key]);
            }
            $this->commit();
        } catch (\PDOException $e) {
            $this->rollback();
            throw new StorageException('Could not adjust budget counters: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getCounter(string $key): int
    {
        try {
            $statement = $this->pdo->prepare("SELECT amount FROM {$this->counters} WHERE counter_key = ?");
            $statement->execute([$key]);
            $value = $statement->fetchColumn();
        } catch (\PDOException $e) {
            throw new StorageException('Could not read a budget counter: ' . $e->getMessage(), 0, $e);
        }

        return $value === false ? 0 : (int) $value;
    }

    public function logUsage(UsageRecord $record): void
    {
        $row = $record->toArray();
        $row['cache_hit'] = $record->cacheHit ? 1 : 0;
        $row['period_month'] = gmdate('Y-m', $record->createdAt);
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->usage,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        try {
            self::run($this->pdo->prepare($sql), array_values($row));
        } catch (\PDOException $e) {
            throw new StorageException('Could not write the usage log: ' . $e->getMessage(), 0, $e);
        }
    }

    public function aggregateUsage(int $from, int $to, string $groupBy): array
    {
        Aggregator::assertGroupBy($groupBy);
        $none = "'(none)'";
        $group = match ($groupBy) {
            'tenant' => "COALESCE(tenant_id, $none)",
            'user' => "COALESCE(user_id, $none)",
            'feature' => "COALESCE(feature, $none)",
            'model' => $this->driver === 'sqlite' ? "provider || ':' || model" : "CONCAT(provider, ':', model)",
            'provider' => 'provider',
            'day' => 'created_at - (created_at % 86400)',
            default => 'status',
        };
        $sql = "SELECT $group AS grp, COUNT(*) AS requests, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens,
                       SUM(cached_tokens) AS cached_tokens, SUM(cost_micros) AS cost_micros, SUM(cache_hit) AS cache_hits,
                       SUM(CASE WHEN status = 'ok' THEN 0 ELSE 1 END) AS errors, AVG(latency_ms) AS avg_latency_ms
                  FROM {$this->usage}
                 WHERE created_at >= ? AND created_at < ?
              GROUP BY grp";
        try {
            $statement = $this->pdo->prepare($sql);
            self::run($statement, [$from, $to]);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new StorageException('Could not read the usage log: ' . $e->getMessage(), 0, $e);
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'group' => $groupBy === 'day' ? gmdate('Y-m-d', (int) $row['grp']) : (string) $row['grp'],
                'requests' => (int) $row['requests'],
                'input_tokens' => (int) $row['input_tokens'],
                'output_tokens' => (int) $row['output_tokens'],
                'cached_tokens' => (int) $row['cached_tokens'],
                'cost_micros' => (int) $row['cost_micros'],
                'cache_hits' => (int) $row['cache_hits'],
                'errors' => (int) $row['errors'],
                'avg_latency_ms' => (int) round((float) $row['avg_latency_ms']),
            ];
        }

        return Aggregator::sort($result);
    }

    public function prune(int $now): int
    {
        try {
            $statement = $this->pdo->prepare("DELETE FROM {$this->counters} WHERE expires_at < ?");
            self::run($statement, [$now]);

            return $statement->rowCount();
        } catch (\PDOException $e) {
            throw new StorageException('Could not prune counters: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Executes with typed bindings. Plain execute([...]) binds everything as
     * strings, and SQLite then compares "amount + ? <= ?" as text, which
     * would silently disable the limit check.
     *
     * @param list<int|string|bool|null> $params
     */
    private static function run(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $i => $value) {
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $statement->bindValue($i + 1, $value, $type);
        }
        $statement->execute();
    }

    private function begin(): void
    {
        if ($this->driver === 'sqlite') {
            // IMMEDIATE takes the write lock up front, so two writers cannot deadlock upgrading their locks.
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
    }

    private function commit(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('COMMIT');
        } else {
            $this->pdo->commit();
        }
    }

    private function rollback(): void
    {
        try {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\PDOException) {
            // No transaction was active.
        }
    }
}
