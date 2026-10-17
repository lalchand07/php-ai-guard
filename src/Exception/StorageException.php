<?php

declare(strict_types=1);

namespace PhpAiGuard\Exception;

/**
 * Thrown when the storage backend fails (database error, unwritable file, ...).
 */
class StorageException extends AiGuardException
{
}
