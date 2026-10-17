<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * A temporary provider failure: retry with backoff, then fall back.
 */
class TransientProviderException extends ProviderException
{
    private ?float $retryAfter = null;

    public function isRetryable(): bool
    {
        return true;
    }

    /** Seconds the provider asked us to wait (Retry-After), if any. */
    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    public function withRetryAfter(?float $seconds): static
    {
        $this->retryAfter = $seconds;

        return $this;
    }
}
