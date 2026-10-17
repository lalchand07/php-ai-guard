<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * Base class for every exception thrown by php-ai-guard.
 *
 * Catch this to handle all library errors in one place.
 */
class AiGuardException extends \RuntimeException
{
}
