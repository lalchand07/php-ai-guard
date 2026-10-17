<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * The provider answered with a 5xx / overloaded error.
 */
class ProviderUnavailableException extends TransientProviderException
{
}
