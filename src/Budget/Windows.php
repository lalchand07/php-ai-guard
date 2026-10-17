<?php

declare(strict_types=1);

namespace PhpAiGuard\Budget;

/**
 * Calendar windows in the configured timezone.
 *
 * @internal
 */
final class Windows
{
    public function __construct(private \DateTimeZone $timezone)
    {
    }

    public function local(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone($this->timezone);
    }

    /** Period label used in counter keys: "2026-10-17" or "2026-10". */
    public function period(string $window, \DateTimeImmutable $now): string
    {
        return $this->local($now)->format($window === 'daily' ? 'Y-m-d' : 'Y-m');
    }

    /** When the current window ends (= when the budget resets). */
    public function resetsAt(string $window, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $local = $this->local($now);

        return $window === 'daily'
            ? $local->setTime(0, 0)->modify('+1 day')
            : $local->modify('first day of next month')->setTime(0, 0);
    }

    /**
     * @return array{0: int, 1: int} [from, to) unix timestamps of a "YYYY-MM" month
     */
    public function monthRange(string $month): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m', $month, $this->timezone);
        if ($start === false) {
            throw new \InvalidArgumentException(sprintf('Invalid month "%s", expected YYYY-MM.', $month));
        }

        return [$start->getTimestamp(), $start->modify('+1 month')->getTimestamp()];
    }
}
