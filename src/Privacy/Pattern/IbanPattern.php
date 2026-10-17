<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy\Pattern;

/**
 * IBANs (with or without spaces) passing the ISO 13616 mod-97 check.
 */
final class IbanPattern extends RegexPattern
{
    public function __construct()
    {
        parent::__construct('IBAN', '/(?<![A-Z0-9])[A-Z]{2}\d{2}(?: ?[A-Z0-9]){11,30}(?![A-Z0-9])/i', [self::class, 'isValid']);
    }

    public static function isValid(string $candidate): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $candidate) ?? '');
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $value = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
            foreach (str_split($value) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
