<?php

declare(strict_types=1);

namespace PhpAiGuard\Retry;

/**
 * Exponential backoff with full jitter, honouring Retry-After.
 */
final class RetryPolicy
{
    /** @var callable(): float */
    private $random;

    /**
     * @param int   $maxRetries    retries per target before falling back to the next one
     * @param float $maxRetryAfter longest Retry-After (seconds) we are willing to wait; longer → fall back immediately
     * @param callable(): float|null $random returns a float in [0, 1); injectable for tests
     */
    public function __construct(
        public int $maxRetries = 2,
        public int $baseDelayMs = 500,
        public int $maxDelayMs = 8000,
        public float $maxRetryAfter = 30.0,
        ?callable $random = null
    ) {
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * Seconds to wait before retry number $retry (0-based), or null to stop
     * retrying this target.
     */
    public function delay(int $retry, ?float $retryAfter = null): ?float
    {
        if ($retry >= $this->maxRetries) {
            return null;
        }
        if ($retryAfter !== null) {
            return $retryAfter > $this->maxRetryAfter ? null : $retryAfter;
        }
        $cap = min($this->maxDelayMs, $this->baseDelayMs * (2 ** $retry));

        return (($this->random)() * $cap) / 1000;
    }
}
