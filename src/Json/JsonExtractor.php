<?php

declare(strict_types=1);

namespace PhpAiGuard\Json;

/**
 * Pulls a JSON value out of model output, tolerating ```json fences and
 * short prose around it.
 */
final class JsonExtractor
{
    /**
     * @return array{ok: bool, value: mixed, error: ?string}
     */
    public static function extract(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json|JSON)?\s*(.*?)```/s', $text, $match) === 1) {
            $text = trim($match[1]);
        }

        $decoded = self::decode($text);
        if ($decoded['ok']) {
            return $decoded;
        }

        // Fall back to the outermost {...} or [...] block.
        $start = strcspn($text, '{[');
        if ($start < strlen($text)) {
            $close = $text[$start] === '{' ? '}' : ']';
            $end = strrpos($text, $close);
            if ($end !== false && $end > $start) {
                $candidate = self::decode(substr($text, $start, $end - $start + 1));
                if ($candidate['ok']) {
                    return $candidate;
                }
            }
        }

        return ['ok' => false, 'value' => null, 'error' => 'the response is not valid JSON (' . $decoded['error'] . ')'];
    }

    /**
     * @return array{ok: bool, value: mixed, error: ?string}
     */
    private static function decode(string $text): array
    {
        if ($text === '') {
            return ['ok' => false, 'value' => null, 'error' => 'empty response'];
        }
        try {
            return ['ok' => true, 'value' => json_decode($text, true, 512, JSON_THROW_ON_ERROR), 'error' => null];
        } catch (\JsonException $e) {
            return ['ok' => false, 'value' => null, 'error' => $e->getMessage()];
        }
    }
}
