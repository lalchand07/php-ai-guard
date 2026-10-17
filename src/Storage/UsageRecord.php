<?php

declare(strict_types=1);

namespace PhpAiGuard\Storage;

/**
 * One row of the usage log. Costs are integer micro-dollars (1 USD = 1 000 000).
 */
final class UsageRecord
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const STATUS_BUDGET_REFUSED = 'budget_refused';
    public const STATUS_RATE_LIMITED = 'rate_limited';
    public const STATUS_PRIVACY_SKIPPED = 'privacy_skipped';

    public function __construct(
        public int $createdAt,
        public string $provider,
        public string $model,
        public string $status,
        public ?string $tenant = null,
        public ?string $user = null,
        public ?string $feature = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedTokens = 0,
        public int $costMicros = 0,
        public int $latencyMs = 0,
        public bool $cacheHit = false,
        public ?string $errorType = null,
        public ?string $promptText = null,
        public ?string $responseText = null
    ) {
    }

    public function costUsd(): float
    {
        return $this->costMicros / 1_000_000;
    }

    /** @return array<string, int|string|bool|null> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'tenant_id' => $this->tenant,
            'user_id' => $this->user,
            'feature' => $this->feature,
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cached_tokens' => $this->cachedTokens,
            'cost_micros' => $this->costMicros,
            'latency_ms' => $this->latencyMs,
            'cache_hit' => $this->cacheHit,
            'status' => $this->status,
            'error_type' => $this->errorType,
            'prompt_text' => $this->promptText,
            'response_text' => $this->responseText,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $str = static fn (string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;

        return new self(
            (int) ($row['created_at'] ?? 0),
            (string) ($row['provider'] ?? ''),
            (string) ($row['model'] ?? ''),
            (string) ($row['status'] ?? self::STATUS_OK),
            $str('tenant_id'),
            $str('user_id'),
            $str('feature'),
            (int) ($row['input_tokens'] ?? 0),
            (int) ($row['output_tokens'] ?? 0),
            (int) ($row['cached_tokens'] ?? 0),
            (int) ($row['cost_micros'] ?? 0),
            (int) ($row['latency_ms'] ?? 0),
            (bool) ($row['cache_hit'] ?? false),
            $str('error_type'),
            $str('prompt_text'),
            $str('response_text')
        );
    }
}
