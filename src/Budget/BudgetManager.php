<?php

declare(strict_types=1);

namespace PhpAiGuard\Budget;

use PhpAiGuard\Clock\ClockInterface;
use PhpAiGuard\Context;
use PhpAiGuard\Exception\BudgetExceededException;
use PhpAiGuard\Storage\CounterOperation;
use PhpAiGuard\Storage\StorageInterface;

/**
 * Reserve-then-settle budget enforcement.
 *
 * Before a call the worst-case cost is added to every applicable counter in
 * one atomic operation (refused if any limit would be crossed). After the call
 * the difference between reserved and actual cost is given back.
 */
final class BudgetManager
{
    public function __construct(
        private StorageInterface $storage,
        private BudgetPolicy $policy,
        private ClockInterface $clock,
        private Windows $windows
    ) {
    }

    public static function toMicros(float $usd): int
    {
        return (int) round($usd * 1_000_000);
    }

    /**
     * @throws BudgetExceededException
     */
    public function reserve(Context $context, float $estimateUsd): Reservation
    {
        // Round up so many tiny calls cannot slip through on rounding.
        $micros = (int) ceil($estimateUsd * 1_000_000);
        if ($micros <= 0 || $this->policy->isEmpty()) {
            return new Reservation([], 0);
        }

        $now = $this->clock->now();
        $operations = [];
        $meta = [];
        foreach ($this->scopes($context) as [$scope, $id]) {
            foreach (BudgetPolicy::WINDOWS as $window) {
                $limit = $this->policy->limit($scope, $id, $window);
                if ($limit === null) {
                    continue;
                }
                $key = $this->key($scope, $id, $window, $now);
                $operations[] = new CounterOperation($key, $micros, self::toMicros($limit), $this->windows->resetsAt($window, $now)->getTimestamp() + 86400);
                $meta[$key] = [$scope, $id, $window, $limit];
            }
        }
        if ($operations === []) {
            return new Reservation([], 0);
        }

        $failed = $this->storage->reserve($operations);
        if ($failed !== null) {
            [$scope, $id, $window, $limit] = $meta[$failed];
            throw new BudgetExceededException(
                $scope,
                $id,
                $window,
                $limit,
                $this->storage->getCounter($failed) / 1_000_000,
                $estimateUsd,
                $this->windows->resetsAt($window, $now)
            );
        }

        return new Reservation(array_keys($meta), $micros);
    }

    /** Replaces the reserved amount with the actual cost. */
    public function settle(Reservation $reservation, float $actualUsd): void
    {
        if ($reservation->isClosed()) {
            return;
        }
        $reservation->close();
        $delta = self::toMicros($actualUsd) - $reservation->micros;
        if ($reservation->keys === [] || $delta === 0) {
            return;
        }
        $this->storage->adjust(array_fill_keys($reservation->keys, $delta));
    }

    /** Gives the whole reservation back (the call failed and cost nothing). */
    public function release(Reservation $reservation): void
    {
        $this->settle($reservation, 0.0);
    }

    /**
     * Remaining USD for a scope/window, or null if it has no limit.
     */
    public function remaining(string $scope, ?string $id, string $window): ?float
    {
        $limit = $this->policy->limit($scope, $id, $window);
        if ($limit === null) {
            return null;
        }
        $spent = $this->storage->getCounter($this->key($scope, $id, $window, $this->clock->now())) / 1_000_000;

        return max(0.0, $limit - $spent);
    }

    /** USD counted against a scope/window in the current period. */
    public function spent(string $scope, ?string $id, string $window): float
    {
        return $this->storage->getCounter($this->key($scope, $id, $window, $this->clock->now())) / 1_000_000;
    }

    /**
     * @return list<array{0: string, 1: ?string}>
     */
    private function scopes(Context $context): array
    {
        $scopes = [['global', null]];
        if ($context->tenant !== null) {
            $scopes[] = ['tenant', $context->tenant];
        }
        if ($context->user !== null) {
            $scopes[] = ['user', $context->user];
        }

        return $scopes;
    }

    private function key(string $scope, ?string $id, string $window, \DateTimeImmutable $now): string
    {
        return sprintf('budget:%s:%s:%s:%s', $scope, CounterKey::id($id), $window, $this->windows->period($window, $now));
    }
}
