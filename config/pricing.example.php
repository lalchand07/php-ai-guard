<?php

/*
 * ============================================================================
 *  EXAMPLE VALUES — VERIFY AGAINST PROVIDER PRICING PAGES BEFORE USE
 * ============================================================================
 *
 * These numbers are NOT authoritative and php-ai-guard does not keep them up
 * to date. Providers change prices, add surcharges (long context, data
 * residency, priority tiers) and run time-limited promotions. Copy this file
 * into your project, check every row against the official pricing page and
 * keep it current: your budgets are only as accurate as these numbers.
 *
 *   Anthropic : https://platform.claude.com/docs/en/about-claude/pricing
 *   Gemini    : https://ai.google.dev/gemini-api/docs/pricing
 *   OpenAI    : https://openai.com/api/pricing/
 *   Groq      : https://groq.com/pricing
 *   OpenRouter: https://openrouter.ai/models  (per-model prices)
 *
 * Format: USD per 1 million tokens.
 *   input        normal input tokens
 *   output       output tokens (including thinking/reasoning tokens)
 *   cache_read   input tokens served from the provider's prompt cache
 *                (default when omitted: same as input)
 *   cache_write  input tokens written to the prompt cache
 *                (default when omitted: 2 × input, deliberately pessimistic)
 *   long_context optional higher prices above a prompt-length threshold
 *   free         true for local or free models (cost is always 0)
 *
 * '*' matches any model of that provider. Models with no row are refused
 * unless you set 'unknown_pricing' => 'allow'.
 *
 * Values below were copied from the providers' pricing pages on 2026-10-17.
 */

declare(strict_types=1);

return [
    'anthropic' => [
        // 5-minute cache writes cost 1.25 × input; 1-hour writes cost 2 × input.
        'claude-haiku-5-5' => [
            'input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125,
            'long_context' => ['threshold' => 100000, 'input' => 0.50, 'output' => 2.50, 'cache_read' => 0.05, 'cache_write' => 0.625],
        ],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
    ],

    'gemini' => [
        // Paid-tier promotional prices valid through 2026-12-31; they double on 2027-01-01.
        'gemini-3.8-flash' => ['input' => 0.75, 'output' => 3.75, 'cache_read' => 0.075],
        'gemini-3.5-flash-lite' => ['input' => 0.30, 'output' => 2.50],
    ],

    'groq' => [
        'openai/gpt-oss-20b' => ['input' => 0.075, 'output' => 0.30],
        'openai/gpt-oss-120b' => ['input' => 0.15, 'output' => 0.60],
    ],

    // 'openai' => [
    //     'your-model-id' => ['input' => 0.00, 'output' => 0.00, 'cache_read' => 0.00],
    // ],

    // Local models cost nothing per token.
    'ollama' => [
        '*' => ['free' => true],
    ],
];
