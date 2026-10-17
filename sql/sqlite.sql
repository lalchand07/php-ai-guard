-- php-ai-guard schema for SQLite.
-- Generated from PhpAiGuard\Storage\SchemaSql; you can also run: vendor/bin/ai-guard install
-- Change the ai_guard_ prefix if you configured a different table_prefix.

CREATE TABLE IF NOT EXISTS ai_guard_counters (
    counter_key TEXT PRIMARY KEY,
    amount INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS ai_guard_counters_expires ON ai_guard_counters (expires_at);

CREATE TABLE IF NOT EXISTS ai_guard_usage (
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
);

CREATE INDEX IF NOT EXISTS ai_guard_usage_created ON ai_guard_usage (created_at);

CREATE INDEX IF NOT EXISTS ai_guard_usage_month_tenant ON ai_guard_usage (period_month, tenant_id);

CREATE INDEX IF NOT EXISTS ai_guard_usage_month_user ON ai_guard_usage (period_month, user_id);
