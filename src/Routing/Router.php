<?php

declare(strict_types=1);

namespace PhpAiGuard\Routing;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Request;

/**
 * Turns a request into an ordered fallback chain of targets.
 *
 * Resolution order: explicit "provider:model" → tier name → the tier mapped
 * to the request's feature → the default tier.
 */
final class Router
{
    /** @var array<string, list<Target>> */
    private array $tiers = [];

    /**
     * @param array<string, string|list<string>> $tiers    tier name => "provider:model" or a list of them (fallback order)
     * @param array<string, string>              $features feature tag => tier name
     */
    public function __construct(array $tiers, private array $features = [], private ?string $defaultTier = null)
    {
        foreach ($tiers as $name => $targets) {
            $list = [];
            foreach ((array) $targets as $target) {
                $list[] = Target::parse((string) $target);
            }
            if ($list === []) {
                throw new ConfigurationException(sprintf('Tier "%s" has no targets.', $name));
            }
            $this->tiers[(string) $name] = $list;
        }
        if ($this->defaultTier === null && $this->tiers !== []) {
            $this->defaultTier = isset($this->tiers['standard']) ? 'standard' : (string) array_key_first($this->tiers);
        }
        if ($this->defaultTier !== null && !isset($this->tiers[$this->defaultTier])) {
            throw new ConfigurationException(sprintf('The default tier "%s" is not defined in "tiers".', $this->defaultTier));
        }
        foreach ($this->features as $feature => $tier) {
            if (!isset($this->tiers[$tier])) {
                throw new ConfigurationException(sprintf('Feature "%s" is mapped to unknown tier "%s".', $feature, $tier));
            }
        }
    }

    /**
     * @return list<Target>
     */
    public function resolve(Request $request): array
    {
        $route = $request->route;
        if ($route !== null && $route !== '') {
            if (isset($this->tiers[$route])) {
                return $this->tiers[$route];
            }
            if (str_contains($route, ':')) {
                return [Target::parse($route)];
            }
            throw new ConfigurationException(sprintf('Unknown tier "%s". Defined tiers: %s. Use "provider:model" to pick a model directly.', $route, implode(', ', array_keys($this->tiers)) ?: 'none'));
        }

        $feature = $request->context->feature;
        if ($feature !== null && isset($this->features[$feature])) {
            return $this->tiers[$this->features[$feature]];
        }
        if ($this->defaultTier === null) {
            throw new ConfigurationException('No model selected: configure "tiers" (and optionally "default_tier") or pass "model" => "provider:model".');
        }

        return $this->tiers[$this->defaultTier];
    }

    /** @return array<string, list<Target>> */
    public function tiers(): array
    {
        return $this->tiers;
    }
}
