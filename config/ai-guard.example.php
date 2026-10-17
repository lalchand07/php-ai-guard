<?php

/*
 * php-ai-guard configuration — copy to your project as ai-guard.php.
 *
 * Keep API keys out of this file: read them from environment variables
 * (cPanel: "Environment Variables" in some panels, or SetEnv in .htaccess, or
 * a config file outside public_html).
 *
 * The CLI (vendor/bin/ai-guard report) loads this same file.
 */

declare(strict_types=1);

return [
    // ---------------------------------------------------------------------
    // Providers: name => settings. "type" defaults to the name, so a provider
    // called "anthropic" needs no type. Types: anthropic, gemini, openai,
    // groq, openrouter, nvidia, ollama, openai-compatible.
    // ---------------------------------------------------------------------
    'providers' => [
        'anthropic' => [
            'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
            'allows_personal_data' => true,      // paid API: you decide
            // 'prompt_cache' => true,           // true (5 min) or '1h'; cache writes cost more, reads much less
            // 'json_mode' => 'native',          // structured outputs on supported models (default: 'prompt')
        ],
        'gemini' => [
            'api_key' => getenv('GEMINI_API_KEY') ?: '',
            // Free tiers may use prompts to improve products: never send them unredacted personal data.
            'allows_personal_data' => false,
        ],
        'groq' => [
            'api_key' => getenv('GROQ_API_KEY') ?: '',
            'allows_personal_data' => false,
        ],
        // 'openrouter' => ['api_key' => getenv('OPENROUTER_API_KEY') ?: '', 'headers' => ['HTTP-Referer' => 'https://example.com', 'X-OpenRouter-Title' => 'My App']],
        // 'openai'     => ['api_key' => getenv('OPENAI_API_KEY') ?: ''],
        // 'nvidia'     => ['api_key' => getenv('NVIDIA_API_KEY') ?: ''],
        // 'local'      => ['type' => 'ollama'],   // http://localhost:11434/v1
        // 'vllm'       => ['type' => 'openai-compatible', 'base_url' => 'http://10.0.0.5:8000/v1', 'api_key' => ''],
    ],

    // Default request timeout in seconds (per provider: 'timeout' => 60).
    'timeout' => 30,

    // ---------------------------------------------------------------------
    // Model routing: named tiers → fallback chain ("provider:model", tried in order).
    // ---------------------------------------------------------------------
    'tiers' => [
        'cheap' => ['groq:openai/gpt-oss-20b', 'gemini:gemini-3.5-flash-lite'],
        'standard' => ['anthropic:claude-haiku-5-5', 'gemini:gemini-3.8-flash'],
        'smart' => ['anthropic:claude-sonnet-5-5', 'gemini:gemini-3.8-flash'],
    ],
    'default_tier' => 'standard',
    // Feature tag → tier, so $ai->withFeature('classify') uses the cheap tier.
    'features' => [
        'classify' => 'cheap',
        'draft' => 'standard',
    ],

    // ---------------------------------------------------------------------
    // Pricing (USD per 1M tokens). EXAMPLE VALUES — verify them yourself.
    // ---------------------------------------------------------------------
    'pricing' => require __DIR__ . '/pricing.example.php',
    'unknown_pricing' => 'refuse',           // or 'allow' (cost logged as $0)

    // ---------------------------------------------------------------------
    // Budgets (USD). A call is refused BEFORE it is sent if its worst-case
    // cost could exceed any applicable limit.
    // ---------------------------------------------------------------------
    'budgets' => [
        'global' => ['daily' => 5.00, 'monthly' => 100.00],
        'tenant' => ['monthly' => 20.00],                  // every company
        'user' => ['daily' => 0.25, 'monthly' => 2.00],    // every user
        'tenants' => ['big-customer' => ['monthly' => 200.00]],
        'users' => ['admin-1' => ['daily' => 5.00]],
    ],
    'budget_fallback' => false,              // true: try cheaper tier members when the first would exceed a budget

    // Requests per minute.
    'rate_limits' => ['user' => 10, 'tenant' => 60, 'global' => 300],

    // ---------------------------------------------------------------------
    // Storage for budgets, rate limits and the usage log.
    // ---------------------------------------------------------------------
    'storage' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/../var/ai-guard.sqlite',   // outside public_html!
    ],
    // 'storage' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'app', 'username' => 'app', 'password' => getenv('DB_PASSWORD') ?: '', 'table_prefix' => 'ai_guard_'],
    // 'storage' => ['driver' => 'file', 'path' => __DIR__ . '/../var/ai-guard'],

    // Response cache: identical requests are paid for once.
    'cache' => [
        'driver' => 'file',
        'path' => __DIR__ . '/../var/ai-cache',
        'ttl' => 86400,
        'default' => true,                   // false: only when a call passes 'cache' => true
    ],

    // ---------------------------------------------------------------------
    // Privacy
    // ---------------------------------------------------------------------
    'privacy' => [
        'redact' => false,                   // true: redact for every provider
        'restore' => true,                   // put original values back into answers
        'patterns' => ['mrz', 'iban', 'card', 'email', 'phone'],
        'custom' => ['EMPLOYEE_ID' => '/\bEMP-\d{6}\b/'],
        'on_disallowed' => 'redact',         // or 'skip' providers with allows_personal_data = false
    ],

    // Retries per provider before falling back to the next one.
    'retry' => ['max_retries' => 2, 'base_delay_ms' => 500, 'max_delay_ms' => 8000, 'max_retry_after' => 30],

    'defaults' => ['max_tokens' => 1024],
    'timezone' => 'UTC',                     // budget days/months follow this timezone
    'log_prompts' => false,                  // true: store (redacted) prompts and answers in the usage log
    // 'logger' => $psr3Logger,              // any PSR-3 logger
    // 'http_client' => new PhpAiGuard\Http\Psr18HttpClient($client, $requestFactory, $streamFactory),
];
