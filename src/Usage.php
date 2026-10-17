<?php

declare(strict_types=1);

namespace PhpAiGuard;

/**
 * Token usage reported by the provider.
 *
 * inputTokens counts every prompt token, including cached ones;
 * cacheReadTokens and cacheWriteTokens say how many of them were read from /
 * written to the provider's prompt cache.
 */
final class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function add(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens
        );
    }

    /** @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int} */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['input_tokens'] ?? 0),
            (int) ($data['output_tokens'] ?? 0),
            (int) ($data['cache_read_tokens'] ?? 0),
            (int) ($data['cache_write_tokens'] ?? 0)
        );
    }
}
