<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy;

/**
 * Placeholder ↔ original value for one request. Never logged or cached.
 */
final class RedactionMap
{
    /** @var array<string, string> placeholder => original */
    private array $byPlaceholder = [];

    /** @var array<string, string> original => placeholder */
    private array $byValue = [];

    /** @var array<string, int> */
    private array $counters = [];

    public function placeholderFor(string $label, string $value): string
    {
        if (isset($this->byValue[$value])) {
            return $this->byValue[$value];
        }
        $this->counters[$label] = ($this->counters[$label] ?? 0) + 1;
        $placeholder = sprintf('[%s_%d]', $label, $this->counters[$label]);
        $this->byValue[$value] = $placeholder;
        $this->byPlaceholder[$placeholder] = $value;

        return $placeholder;
    }

    public function isEmpty(): bool
    {
        return $this->byPlaceholder === [];
    }

    public function count(): int
    {
        return count($this->byPlaceholder);
    }

    /** @return array<string, string> placeholder => original */
    public function all(): array
    {
        return $this->byPlaceholder;
    }

    public function restore(string $text): string
    {
        if ($this->byPlaceholder === []) {
            return $text;
        }

        return strtr($text, $this->byPlaceholder);
    }

    /**
     * Restores placeholders inside every string of a decoded JSON structure.
     */
    public function restoreValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->restore($value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[is_string($key) ? $this->restore($key) : $key] = $this->restoreValue($item);
            }

            return $result;
        }

        return $value;
    }
}
