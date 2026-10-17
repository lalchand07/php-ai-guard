<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * Thrown when a user or tenant sends more requests per minute than allowed.
 */
class RateLimitExceededException extends AiGuardException
{
    public function __construct(
        private string $scope,
        private ?string $scopeId,
        private int $limit,
        private int $retryAfterSeconds
    ) {
        $who = $scope === 'global' ? 'the application' : sprintf('%s "%s"', $scope, (string) $scopeId);
        parent::__construct(sprintf(
            'AI request blocked: rate limit of %d requests per minute reached for %s. Retry in %d seconds.',
            $limit,
            $who,
            $retryAfterSeconds
        ));
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getScopeId(): ?string
    {
        return $this->scopeId;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function userMessage(): string
    {
        return sprintf('Too many requests. Please wait %d seconds and try again.', $this->retryAfterSeconds);
    }
}
