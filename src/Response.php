<?php

declare(strict_types=1);

namespace PhpAiGuard;

/**
 * The result of a successful call.
 */
final class Response
{
    /**
     * @param array<mixed>|null    $json     decoded + validated JSON when JSON output was requested
     * @param list<Attempt>        $attempts every try made, including failed ones before a fallback
     * @param array<string, mixed> $raw      the provider's decoded response body (empty for cache hits)
     */
    public function __construct(
        public string $text,
        public Usage $usage,
        public float $cost,
        public int $latencyMs,
        public string $provider,
        public string $model,
        public bool $cacheHit = false,
        public ?string $finishReason = null,
        public ?array $json = null,
        public array $attempts = [],
        public array $raw = []
    ) {
    }

    /** True when the answer was cut off by max_tokens. */
    public function isTruncated(): bool
    {
        return in_array($this->finishReason, ['max_tokens', 'length', 'MAX_TOKENS'], true);
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
