<?php

declare(strict_types=1);

namespace PhpAiGuard\Storage;

use PhpAiGuard\Exception\ConfigurationException;

/**
 * DDL for the PDO storage. The same statements are shipped in sql/*.sql.
 */
final class SchemaSql
{
    /**
     * @return list<string>
     */
    public static function statements(string $driver, string $prefix = 'ai_guard_'): array
    {
        if ($driver === 'sqlite') {
            return [
                "CREATE TABLE IF NOT EXISTS {$prefix}counters (
                    counter_key TEXT PRIMARY KEY,
                    amount INTEGER NOT NULL DEFAULT 0,
                    expires_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )",
                "CREATE INDEX IF NOT EXISTS {$prefix}counters_expires ON {$prefix}counters (expires_at)",
                "CREATE TABLE IF NOT EXISTS {$prefix}usage (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    created_at INTEGER NOT NULL,
                    period_month TEXT NOT NULL,
                    tenant_id TEXT NULL,
                    user_id TEXT NULL,
                    feature TEXT NULL,
                    provider TEXT NOT NULL,
                    model TEXT NOT NULL,
                    input_tokens INTEGER NOT NULL DEFAULT 0,
                    output_tokens INTEGER NOT NULL DEFAULT 0,
                    cached_tokens INTEGER NOT NULL DEFAULT 0,
                    cost_micros INTEGER NOT NULL DEFAULT 0,
                    latency_ms INTEGER NOT NULL DEFAULT 0,
                    cache_hit INTEGER NOT NULL DEFAULT 0,
                    status TEXT NOT NULL,
                    error_type TEXT NULL,
                    prompt_text TEXT NULL,
                    response_text TEXT NULL
                )",
                "CREATE INDEX IF NOT EXISTS {$prefix}usage_created ON {$prefix}usage (created_at)",
                "CREATE INDEX IF NOT EXISTS {$prefix}usage_month_tenant ON {$prefix}usage (period_month, tenant_id)",
                "CREATE INDEX IF NOT EXISTS {$prefix}usage_month_user ON {$prefix}usage (period_month, user_id)",
            ];
        }
        if ($driver === 'mysql') {
            return [
                "CREATE TABLE IF NOT EXISTS {$prefix}counters (
                    counter_key VARCHAR(191) NOT NULL PRIMARY KEY,
                    amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    expires_at BIGINT NOT NULL,
                    updated_at BIGINT NOT NULL,
                    KEY {$prefix}counters_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS {$prefix}usage (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    created_at BIGINT NOT NULL,
                    period_month CHAR(7) NOT NULL,
                    tenant_id VARCHAR(191) NULL,
                    user_id VARCHAR(191) NULL,
                    feature VARCHAR(100) NULL,
                    provider VARCHAR(64) NOT NULL,
                    model VARCHAR(191) NOT NULL,
                    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                    cached_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                    cost_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
                    cache_hit TINYINT(1) NOT NULL DEFAULT 0,
                    status VARCHAR(32) NOT NULL,
                    error_type VARCHAR(191) NULL,
                    prompt_text MEDIUMTEXT NULL,
                    response_text MEDIUMTEXT NULL,
                    KEY {$prefix}usage_created (created_at),
                    KEY {$prefix}usage_month_tenant (period_month, tenant_id),
                    KEY {$prefix}usage_month_user (period_month, user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
        }

        throw new ConfigurationException(sprintf('No schema for PDO driver "%s".', $driver));
    }
}
