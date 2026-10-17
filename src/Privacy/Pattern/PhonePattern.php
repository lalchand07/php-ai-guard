<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy\Pattern;

/**
 * Phone numbers: international (+44 20 7946 0958, 0044...) or national with
 * at least 9 digits. Dates, times and plain short numbers are ignored.
 */
final class PhonePattern extends RegexPattern
{
    public function __construct()
    {
        parent::__construct('PHONE', '/(?<![\w+])(?:\+|00)?\(?\d[\d\s().\/-]{6,}\d(?![\w])/', [self::class, 'isValid']);
    }

    public static function isValid(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate) ?? '';
        $international = str_starts_with($candidate, '+') || str_starts_with($candidate, '00');
        $count = strlen($digits);
        if ($count > 15 || $count < ($international ? 8 : 9)) {
            return false;
        }
        // Looks like a date or time (2026-10-17, 17/10/2026, 12.30.45)?
        if (preg_match('/^\d{1,4}[-\/.]\d{1,2}[-\/.]\d{1,4}$/', $candidate) === 1) {
            return false;
        }
        // Long runs that are really amounts or ids written without separators and no leading + / 0.
        if (!$international && $candidate[0] !== '0' && $candidate[0] !== '(' && preg_match('/^\d+$/', $candidate) === 1 && $count > 11) {
            return false;
        }

        return true;
    }
}
