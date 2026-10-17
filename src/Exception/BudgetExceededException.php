<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * Thrown before a call is made when its worst-case cost could exceed a budget.
 *
 * getMessage() is written for developers and logs; userMessage() is safe to
 * show to end users.
 */
class BudgetExceededException extends AiGuardException
{
    public function __construct(
        private string $scope,
        private ?string $scopeId,
        private string $window,
        private float $limit,
        private float $spent,
        private float $estimate,
        private ?\DateTimeImmutable $resetsAt = null,
        ?\Throwable $previous = null
    ) {
        $who = $scope === 'global' ? 'the global' : sprintf('the %s "%s"', $scope, (string) $scopeId);
        $message = sprintf(
            'AI request blocked: the %s budget for %s would be exceeded ($%s of $%s used, this request could cost up to $%s).%s',
            $window,
            $who,
            self::money($spent),
            self::money($limit),
            self::money($estimate),
            $resetsAt !== null ? ' The budget resets on ' . $resetsAt->format('Y-m-d') . '.' : ''
        );
        parent::__construct($message, 0, $previous);
    }

    /** "global", "tenant" or "user". */
    public function getScope(): string
    {
        return $this->scope;
    }

    public function getScopeId(): ?string
    {
        return $this->scopeId;
    }

    /** "daily" or "monthly". */
    public function getWindow(): string
    {
        return $this->window;
    }

    public function getLimit(): float
    {
        return $this->limit;
    }

    public function getSpent(): float
    {
        return $this->spent;
    }

    public function getEstimate(): float
    {
        return $this->estimate;
    }

    public function getRemaining(): float
    {
        return max(0.0, $this->limit - $this->spent);
    }

    public function getResetsAt(): ?\DateTimeImmutable
    {
        return $this->resetsAt;
    }

    /**
     * A short, friendly message suitable for showing to the end user.
     */
    public function userMessage(): string
    {
        $period = $this->window === 'daily' ? 'today' : 'this month';
        $message = $this->scope === 'global'
            ? sprintf('The AI assistant has reached its usage limit for %s.', $period)
            : sprintf('You have reached your AI usage limit for %s.', $period);
        if ($this->resetsAt !== null) {
            $message .= ' It resets on ' . $this->resetsAt->format('j F Y') . '.';
        }

        return $message;
    }

    private static function money(float $value): string
    {
        return number_format($value, $value > 0 && $value < 0.01 ? 4 : 2, '.', '');
    }
}
