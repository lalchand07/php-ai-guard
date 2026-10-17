<?php

declare(strict_types=1);

namespace PhpAiGuard\Support;

/**
 * Multibyte helpers that degrade gracefully when ext-mbstring is missing
 * (it is not always enabled on shared hosting).
 *
 * @internal
 */
final class Str
{
    public static function length(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        $count = preg_match_all('/./su', $text);

        return is_int($count) ? $count : strlen($text);
    }

    public static function limit(string $text, int $max, string $suffix = '…'): string
    {
        if (self::length($text) <= $max) {
            return $text;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max, 'UTF-8') . $suffix;
        }
        preg_match('/^.{0,' . $max . '}/su', $text, $match);

        return ($match[0] ?? substr($text, 0, $max)) . $suffix;
    }
}
