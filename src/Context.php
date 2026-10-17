<?php

declare(strict_types=1);

namespace PhpAiGuard;

/**
 * Who is making the call and for what: used for budgets, rate limits and reports.
 */
final class Context
{
    public function __construct(
        public ?string $tenant = null,
        public ?string $user = null,
        public ?string $feature = null
    ) {
    }

    public function with(?string $tenant = null, ?string $user = null, ?string $feature = null): self
    {
        return new self($tenant ?? $this->tenant, $user ?? $this->user, $feature ?? $this->feature);
    }
}
