<?php

declare(strict_types=1);

namespace PhpAiGuard\Budget;

use PhpAiGuard\Exception\ConfigurationException;

/**
 * USD limits per scope and window, from config:
 *
 *     'budgets' => [
 *         'global'  => ['daily' => 10.00, 'monthly' => 200.00],
 *         'tenant'  => ['monthly' => 50.00],             // default for every tenant
 *         'user'    => ['daily' => 0.50, 'monthly' => 5.00],
 *         'tenants' => ['acme' => ['monthly' => 500.00]],  // per-tenant overrides
 *         'users'   => ['42' => ['monthly' => 20.00]],     // per-user overrides
 *     ]
 */
final class BudgetPolicy
{
    public const SCOPES = ['global', 'tenant', 'user'];
    public const WINDOWS = ['daily', 'monthly'];

    /** @var array<string, array<string, float>> */
    private array $defaults = [];

    /** @var array<string, array<string, array<string, float>>> */
    private array $overrides = ['tenant' => [], 'user' => []];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (in_array($key, self::SCOPES, true)) {
                $this->defaults[$key] = self::limits($value, $key);
            } elseif ($key === 'tenants' || $key === 'users') {
                if (!is_array($value)) {
                    throw new ConfigurationException(sprintf('budgets.%s must be an array of id => limits.', $key));
                }
                $scope = $key === 'tenants' ? 'tenant' : 'user';
                foreach ($value as $id => $limits) {
                    $this->overrides[$scope][(string) $id] = self::limits($limits, $key . '.' . $id);
                }
            } else {
                throw new ConfigurationException(sprintf('Unknown budgets key "%s". Use global, tenant, user, tenants or users.', (string) $key));
            }
        }
    }

    /** USD limit, or null when the scope/window is unlimited. */
    public function limit(string $scope, ?string $id, string $window): ?float
    {
        if ($id !== null && isset($this->overrides[$scope][$id]) && array_key_exists($window, $this->overrides[$scope][$id])) {
            return $this->overrides[$scope][$id][$window];
        }

        return $this->defaults[$scope][$window] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->defaults === [] && $this->overrides['tenant'] === [] && $this->overrides['user'] === [];
    }

    /**
     * @return array<string, float>
     */
    private static function limits(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new ConfigurationException(sprintf('budgets.%s must be an array like [\'daily\' => 1.00, \'monthly\' => 20.00].', $label));
        }
        $limits = [];
        foreach ($value as $window => $amount) {
            if (!in_array($window, self::WINDOWS, true)) {
                throw new ConfigurationException(sprintf('budgets.%s: unknown window "%s" (use daily or monthly).', $label, (string) $window));
            }
            if (!is_numeric($amount) || (float) $amount < 0) {
                throw new ConfigurationException(sprintf('budgets.%s.%s must be a non-negative number of USD.', $label, $window));
            }
            $limits[$window] = (float) $amount;
        }

        return $limits;
    }
}
