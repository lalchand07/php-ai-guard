<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy\Pattern;

/**
 * Machine-readable zone lines of passports (ICAO 9303 TD3, 2 × 44 characters)
 * and ID cards (TD1, 3 × 30 characters). Data lines are only accepted when
 * their check digits are valid; name lines must contain "<" fillers.
 */
final class MrzPattern extends RegexPattern
{
    public function __construct()
    {
        parent::__construct('MRZ', '/(?<![A-Z0-9<])[A-Z0-9<]{30}(?:[A-Z0-9<]{14})?(?![A-Z0-9<])/', [self::class, 'isValid']);
    }

    public static function isValid(string $line): bool
    {
        $length = strlen($line);
        if (!str_contains($line, '<')) {
            return false;
        }
        if ($length === 44) {
            if ($line[0] === 'P') {
                return str_contains($line, '<<');
            }

            // TD3 data line: document number, birth date and expiry date check digits.
            return self::check(substr($line, 0, 9), $line[9])
                && self::check(substr($line, 13, 6), $line[19])
                && self::check(substr($line, 21, 6), $line[27]);
        }
        if ($length !== 30) {
            return false;
        }
        if (ctype_digit($line[0])) {
            // TD1 line 2: birth date and expiry date check digits.
            return self::check(substr($line, 0, 6), $line[6]) && self::check(substr($line, 8, 6), $line[14]);
        }
        if (in_array($line[0], ['I', 'A', 'C'], true) && ctype_alpha(substr($line, 2, 3))) {
            return true; // TD1 line 1: document code + issuing state
        }

        // TD1 line 3: SURNAME<<GIVEN<NAMES
        return preg_match('/^[A-Z]+<<[A-Z<]+$/', $line) === 1;
    }

    public static function check(string $field, string $digit): bool
    {
        $weights = [7, 3, 1];
        $sum = 0;
        foreach (str_split($field) as $i => $char) {
            if ($char === '<') {
                $value = 0;
            } elseif (ctype_digit($char)) {
                $value = (int) $char;
            } else {
                $value = ord($char) - 55;
            }
            $sum += $value * $weights[$i % 3];
        }

        return (string) ($sum % 10) === $digit;
    }
}
