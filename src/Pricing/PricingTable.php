<?php

declare(strict_types=1);

namespace PhpAiGuard\Pricing;

/**
 * Prices you control, keyed by provider name then model:
 *
 *     [
 *         'anthropic' => ['claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50]],
 *         'ollama'    => ['*' => ['free' => true]],
 *     ]
 *
 * "*" matches any model of that provider.
 */
final class PricingTable
{
    /** @var array<string, array<string, ModelPrice>> */
    private array $prices = [];

    /**
     * @param array<string, array<string, array<string, mixed>|ModelPrice>> $config
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $provider => $models) {
            if (!is_array($models)) {
                continue;
            }
            foreach ($models as $model => $price) {
                $this->set((string) $provider, (string) $model, $price instanceof ModelPrice ? $price : ModelPrice::fromArray($price, $provider . ':' . $model));
            }
        }
    }

    public function set(string $provider, string $model, ModelPrice $price): void
    {
        $this->prices[$provider][$model] = $price;
    }

    public function find(string $provider, string $model): ?ModelPrice
    {
        $models = $this->prices[$provider] ?? null;
        if ($models === null) {
            return null;
        }
        if (isset($models[$model])) {
            return $models[$model];
        }
        if (str_starts_with($model, 'models/') && isset($models[substr($model, 7)])) {
            return $models[substr($model, 7)];
        }

        return $models['*'] ?? null;
    }
}
