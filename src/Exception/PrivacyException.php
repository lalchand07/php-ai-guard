<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * Thrown when personal data would be sent to a provider that is not allowed to receive it.
 */
class PrivacyException extends AiGuardException
{
}
