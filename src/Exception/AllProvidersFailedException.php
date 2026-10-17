<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

use PhpAiGuard\Attempt;

/**
 * Thrown when every target in the fallback chain failed with a retryable error.
 */
class AllProvidersFailedException extends AiGuardException
{
    /**
     * @param list<Attempt> $attempts
     */
    public function __construct(private array $attempts, ?\Throwable $previous = null)
    {
        $parts = [];
        foreach ($attempts as $attempt) {
            $parts[] = $attempt->target . ' → ' . ($attempt->error ?? $attempt->status);
        }
        parent::__construct('All AI providers failed: ' . implode(' | ', $parts), 0, $previous);
    }

    /** @return list<Attempt> */
    public function getAttempts(): array
    {
        return $this->attempts;
    }
}
