<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * The API key is missing, invalid or lacks permission (HTTP 401/403).
 */
class AuthenticationException extends ProviderException
{
}
