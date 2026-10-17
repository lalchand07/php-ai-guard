<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * The provider could not be reached (DNS, TLS, connection reset, ...).
 */
class ProviderNetworkException extends TransientProviderException
{
}
