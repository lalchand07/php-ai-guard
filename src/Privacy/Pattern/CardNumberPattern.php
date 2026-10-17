<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy\Pattern;

/**
 * Payment card numbers: 13–19 digits (spaces or dashes allowed) passing the Luhn check.
 */
final class CardNumberPattern extends RegexPattern
{
    public function __construct()
    {
        parent::__construct('CARD', '/(?<![\d])\d(?:[ -]?\d){12,18}(?![\d])/', [self::class, 'isValid']);
    }

    public static function isValid(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate) ?? '';
        $length = strlen($digits);
        if ($length < 13 || $length > 19) {
            return false;
        }

        return self::luhn($digits);
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
