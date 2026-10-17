<?php

declare(strict_types=1);

namespace PhpAiGuard\Budget;

use PhpAiGuard\Clock\ClockInterface;
use PhpAiGuard\Context;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\RateLimitExceededException;
use PhpAiGuard\Storage\CounterOperation;
use PhpAiGuard\Storage\StorageInterface;

/**
 * Requests per minute per user / tenant / application (fixed one-minute windows).
 *
 *     'rate_limits' => ['user' => 10, 'tenant' => 60, 'global' => 300]
 */
final class RateLimiter
{
    /** @var array<string, int> */
    private array $limits = [];

    /**
     * @param array<string, mixed> $limits
     */
    public function __construct(private StorageInterface $storage, private ClockInterface $clock, array $limits = [])
    {
        foreach ($limits as $scope => $limit) {
            if (!in_array($scope, BudgetPolicy::SCOPES, true)) {
                throw new ConfigurationException(sprintf('Unknown rate_limits key "%s". Use global, tenant or user.', (string) $scope));
            }
            if (is_array($limit)) {
                $limit = $limit['per_minute'] ?? null;
            }
            if (!is_numeric($limit) || (int) $limit < 1) {
                throw new ConfigurationException(sprintf('rate_limits.%s must be a positive number of requests per minute.', $scope));
            }
            $this->limits[$scope] = (int) $limit;
        }
    }

    public function isEnabled(): bool
    {
        return $this->limits !== [];
    }

    /**
     * Counts one request; throws if any applicable limit is reached.
     *
     * @throws RateLimitExceededException
     */
    public function hit(Context $context): void
    {
        if ($this->limits === []) {
            return;
        }
        $now = $this->clock->now();
        $minute = $now->format('YmdHi');
        $expires = $now->getTimestamp() + 120;
        $operations = [];
        $meta = [];
        $ids = ['global' => null, 'tenant' => $context->tenant, 'user' => $context->user];
        foreach ($this->limits as $scope => $limit) {
            if ($scope !== 'global' && $ids[$scope] === null) {
                continue;
            }
            $key = sprintf('rate:%s:%s:%s', $scope, CounterKey::id($ids[$scope]), $minute);
            $operations[] = new CounterOperation($key, 1, $limit, $expires);
            $meta[$key] = [$scope, $ids[$scope], $limit];
        }
        if ($operations === []) {
            return;
        }
        $failed = $this->storage->reserve($operations);
        if ($failed !== null) {
            [$scope, $id, $limit] = $meta[$failed];
            throw new RateLimitExceededException($scope, $id, $limit, max(1, 60 - (int) $now->format('s')));
        }
    }
}
