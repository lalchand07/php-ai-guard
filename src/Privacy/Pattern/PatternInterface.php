<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy\Pattern;

/**
 * Finds one kind of personal data in text.
 */
interface PatternInterface
{
    /** Placeholder label, e.g. "EMAIL" → [EMAIL_1]. Uppercase letters, digits and underscores. */
    public function label(): string;

    /**
     * @return list<string> the exact substrings to redact
     */
    public function find(string $text): array;
}
