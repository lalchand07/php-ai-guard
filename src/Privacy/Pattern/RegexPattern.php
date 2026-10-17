<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy\Pattern;

use PhpAiGuard\Exception\ConfigurationException;

/**
 * A regular expression, optionally with a validator to drop false positives.
 */
class RegexPattern implements PatternInterface
{
    /** @var (callable(string): bool)|null */
    private $validator;

    /**
     * @param callable(string): bool|null $validator
     */
    public function __construct(private string $label, private string $regex, ?callable $validator = null)
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $label)) {
            throw new ConfigurationException(sprintf('Redaction label "%s" must be uppercase letters, digits and underscores.', $label));
        }
        if (@preg_match($regex, '') === false) {
            throw new ConfigurationException(sprintf('Invalid redaction regex for %s: %s', $label, $regex));
        }
        $this->validator = $validator;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function find(string $text): array
    {
        if (preg_match_all($this->regex, $text, $matches) === false) {
            return [];
        }
        $found = [];
        foreach ($matches[0] as $match) {
            $match = trim($match);
            if ($match !== '' && ($this->validator === null || ($this->validator)($match))) {
                $found[] = $match;
            }
        }

        return array_values(array_unique($found));
    }
}
