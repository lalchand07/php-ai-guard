<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Message;
use PhpAiGuard\Privacy\Pattern\CardNumberPattern;
use PhpAiGuard\Privacy\Pattern\EmailPattern;
use PhpAiGuard\Privacy\Pattern\IbanPattern;
use PhpAiGuard\Privacy\Pattern\MrzPattern;
use PhpAiGuard\Privacy\Pattern\PatternInterface;
use PhpAiGuard\Privacy\Pattern\PhonePattern;
use PhpAiGuard\Privacy\Pattern\RegexPattern;
use PhpAiGuard\Request;

/**
 * Replaces personal data with placeholders such as [EMAIL_1] before a prompt
 * leaves your server, and can put the originals back into the answer.
 *
 * Pattern order matters: more specific patterns (MRZ, IBAN, cards) run before
 * the broad phone pattern.
 */
final class Redactor
{
    public const BUILT_IN = ['mrz', 'iban', 'card', 'email', 'phone'];

    /** @var list<PatternInterface> */
    private array $patterns = [];

    /**
     * @param list<PatternInterface> $patterns
     */
    public function __construct(array $patterns)
    {
        $this->patterns = $patterns;
    }

    /**
     * @param list<string>                                             $builtIn names from BUILT_IN
     * @param array<string, string>|list<array{label: string, regex: string}> $custom  label => regex, or a list of label/regex pairs
     */
    public static function create(array $builtIn = self::BUILT_IN, array $custom = []): self
    {
        $patterns = [];
        foreach ($custom as $key => $value) {
            if (is_array($value)) {
                $patterns[] = new RegexPattern((string) ($value['label'] ?? ''), (string) ($value['regex'] ?? ''));
            } else {
                $patterns[] = new RegexPattern((string) $key, (string) $value);
            }
        }
        foreach (self::BUILT_IN as $name) {
            if (!in_array($name, $builtIn, true)) {
                continue;
            }
            $patterns[] = match ($name) {
                'mrz' => new MrzPattern(),
                'iban' => new IbanPattern(),
                'card' => new CardNumberPattern(),
                'email' => new EmailPattern(),
                default => new PhonePattern(),
            };
        }
        $unknown = array_diff($builtIn, self::BUILT_IN);
        if ($unknown !== []) {
            throw new ConfigurationException('Unknown redaction pattern(s): ' . implode(', ', $unknown) . '. Built-in: ' . implode(', ', self::BUILT_IN) . '.');
        }

        return new self($patterns);
    }

    public function redact(string $text, RedactionMap $map): string
    {
        foreach ($this->patterns as $pattern) {
            $found = $pattern->find($text);
            // Longest first, so "1234 5678" is not partly replaced inside "1234 5678 9012".
            usort($found, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            foreach ($found as $value) {
                $text = str_replace($value, $map->placeholderFor($pattern->label(), $value), $text);
            }
        }

        return $text;
    }

    public function containsPersonalData(string $text): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->find($text) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: Request, 1: RedactionMap}
     */
    public function redactRequest(Request $request): array
    {
        $map = new RedactionMap();
        $messages = [];
        foreach ($request->messages as $message) {
            $messages[] = new Message($message->role, $this->redact($message->content, $map));
        }
        $redacted = $request->withMessages($messages);
        if ($request->system !== null) {
            $redacted = $redacted->withSystem($this->redact($request->system, $map));
        }

        return [$redacted, $map];
    }
}
