<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * Thrown when no price is configured for a model and unknown pricing is set to refuse.
 */
class PricingUnknownException extends AiGuardException
{
}
