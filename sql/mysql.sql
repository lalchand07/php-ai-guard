-- php-ai-guard schema for MySQL 5.7+ / MariaDB 10.3+.
-- Generated from PhpAiGuard\Storage\SchemaSql; you can also run: vendor/bin/ai-guard install
-- Change the ai_guard_ prefix if you configured a different table_prefix.

CREATE TABLE IF NOT EXISTS ai_guard_counters (
    counter_key VARCHAR(191) NOT NULL PRIMARY KEY,
    amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    KEY ai_guard_counters_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_guard_usage (
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
    KEY ai_guard_usage_created (created_at),
    KEY ai_guard_usage_month_tenant (period_month, tenant_id),
    KEY ai_guard_usage_month_user (period_month, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
