<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * The provider rejected the request (HTTP 400/404/413/422). Never retried or failed over.
 */
class InvalidRequestException extends ProviderException
{
}
