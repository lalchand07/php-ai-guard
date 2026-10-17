<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * The provider answered HTTP 429 (rate limited).
 */
class ProviderRateLimitException extends TransientProviderException
{
}
