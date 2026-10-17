<?php

declare(strict_types=1);

namespace PhpAiGuard;

/**
 * Behaviour switches, filled from the config array by AiGuardFactory.
 */
final class EngineOptions
{
    public function __construct(
        public int $defaultMaxTokens = 1024,
        public ?float $defaultTemperature = null,
        public bool $allowUnknownPricing = false,
        public bool $budgetFallback = false,
        public bool $cacheByDefault = false,
        public int $cacheTtl = 3600,
        public bool $logPrompts = false,
        public bool $logUsage = true,
        public string $timezone = 'UTC'
    ) {
    }
}
