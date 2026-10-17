<?php

declare(strict_types=1);

namespace PhpAiGuard\Provider;

use PhpAiGuard\Exception\ProviderException;
use PhpAiGuard\Request;

/**
 * A backend that can complete a chat request. Implement this to add a provider.
 */
interface ProviderInterface
{
    /** The name used in config, routing ("name:model") and logs. */
    public function name(): string;

    /**
     * Whether unredacted personal data may be sent to this provider.
     * Return false for free tiers that may log or train on prompts.
     */
    public function allowsPersonalData(): bool;

    /**
     * Whether this request will use provider-side prompt caching (cache writes
     * can cost more than normal input, so budgets must account for it).
     */
    public function usesPromptCache(Request $request): bool;

    /**
     * @throws ProviderException
     */
    public function complete(Request $request, string $model): ProviderResult;
}
