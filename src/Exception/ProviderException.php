<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * An error returned by (or while talking to) an AI provider.
 */
class ProviderException extends AiGuardException
{
    public function __construct(
        string $message,
        private string $provider = '',
        private ?int $statusCode = null,
        private ?string $errorType = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /** Provider-specific error type, e.g. "overloaded_error" or "RESOURCE_EXHAUSTED". */
    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    /** Whether retrying or falling back to another provider could help. */
    public function isRetryable(): bool
    {
        return false;
    }
}
