<?php

declare(strict_types=1);

namespace PhpAiGuard\Budget;

use PhpAiGuard\Request;
use PhpAiGuard\Support\Str;

/**
 * Deliberately pessimistic input-token estimate used for budget checks.
 *
 * Real tokenizers average ~4 characters per token for English; we assume 3
 * bytes per token, or 1.2 tokens per character for non-Latin scripts —
 * whichever is larger — plus per-message overhead.
 */
final class TokenEstimator
{
    public function __construct(private float $bytesPerToken = 3.0, private float $tokensPerChar = 1.2)
    {
    }

    public function estimateText(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $byBytes = (int) ceil(strlen($text) / $this->bytesPerToken);
        // Only multibyte text can need more than one token per character.
        $byChars = strlen($text) === Str::length($text) ? 0 : (int) ceil(Str::length($text) * $this->tokensPerChar);

        return max($byBytes, $byChars);
    }

    public function estimate(Request $request): int
    {
        $tokens = 16 + $this->estimateText((string) $request->system);
        foreach ($request->messages as $message) {
            $tokens += 8 + $this->estimateText($message->content);
        }
        if ($request->jsonSchema !== null) {
            $tokens += $this->estimateText((string) json_encode($request->jsonSchema));
        }

        return $tokens;
    }
}
