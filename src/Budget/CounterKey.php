<?php

declare(strict_types=1);

namespace PhpAiGuard\Budget;

/**
 * @internal
 */
final class CounterKey
{
    /**
     * Makes ids safe and bounded for use inside counter keys (max 191 chars).
     */
    public static function id(?string $id): string
    {
        if ($id === null) {
            return '-';
        }
        if (strlen($id) <= 64 && preg_match('/^[A-Za-z0-9_.@-]+$/', $id)) {
            return $id;
        }

        return 'h' . substr(hash('sha256', $id), 0, 40);
    }
}
