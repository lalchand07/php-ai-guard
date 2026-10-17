<?php

declare(strict_types=1);

namespace PhpAiGuard;

/**
 * One try against one provider/model, recorded for debugging and exceptions.
 */
final class Attempt
{
    public const OK = 'ok';
    public const CACHE_HIT = 'cache_hit';
    public const ERROR = 'error';
    public const BUDGET_REFUSED = 'budget_refused';
    public const PRIVACY_SKIPPED = 'privacy_skipped';

    public function __construct(
        public string $target,
        public string $status,
        public ?string $error = null,
        public int $latencyMs = 0,
        public ?int $statusCode = null
    ) {
    }
}
