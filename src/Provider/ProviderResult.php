<?php

declare(strict_types=1);

namespace PhpAiGuard\Provider;

use PhpAiGuard\Usage;

final class ProviderResult
{
    /**
     * @param array<string, mixed> $raw decoded response body
     */
    public function __construct(
        public string $text,
        public Usage $usage,
        public ?string $finishReason = null,
        public array $raw = [],
        public ?string $model = null
    ) {
    }
}
