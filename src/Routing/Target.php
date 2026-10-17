<?php

declare(strict_types=1);

namespace PhpAiGuard\Routing;

use PhpAiGuard\Exception\ConfigurationException;

/**
 * A provider + model pair, written "provider:model" (e.g. "ollama:llama3.2:3b";
 * only the first colon separates the provider).
 */
final class Target
{
    public function __construct(public string $provider, public string $model)
    {
        if ($provider === '' || $model === '') {
            throw new ConfigurationException('A target needs both a provider and a model ("provider:model").');
        }
    }

    public static function parse(string $target): self
    {
        $parts = explode(':', $target, 2);
        if (count($parts) !== 2) {
            throw new ConfigurationException(sprintf('Invalid target "%s": expected "provider:model", e.g. "anthropic:claude-haiku-5-5".', $target));
        }

        return new self(trim($parts[0]), trim($parts[1]));
    }

    public function __toString(): string
    {
        return $this->provider . ':' . $this->model;
    }
}
