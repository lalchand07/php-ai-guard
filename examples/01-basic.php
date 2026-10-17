<?php

/*
 * The 10-line version: one call with a budget, per-user logging and fallback.
 *
 *   ANTHROPIC_API_KEY=... php examples/01-basic.php
 *   php examples/01-basic.php            (demo mode, no key needed)
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PhpAiGuard\Exception\BudgetExceededException;
use PhpAiGuard\Exception\RateLimitExceededException;

$ai = example_guard()->forUser('user-42', 'acme-travel')->withFeature('haiku');

try {
    $response = $ai->chat('Write a haiku about PHP running on shared hosting.', ['max_tokens' => 100]);

    echo $response->text, "\n\n";
    printf("Provider: %s / %s%s\n", $response->provider, $response->model, $response->cacheHit ? ' (cache hit)' : '');
    printf("Tokens:   %d in, %d out\n", $response->usage->inputTokens, $response->usage->outputTokens);
    printf("Cost:     $%.6f   Latency: %d ms\n", $response->cost, $response->latencyMs);
    printf("Left this month for user-42: $%.6f\n", $ai->remainingBudget('user'));
} catch (BudgetExceededException | RateLimitExceededException $e) {
    echo $e->userMessage(), "\n";
}
