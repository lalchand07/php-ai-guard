<?php

declare(strict_types=1);

namespace PhpAiGuard\Json;

use PhpAiGuard\Exception\ConfigurationException;

/**
 * Validates decoded JSON against a practical subset of JSON Schema:
 *
 *  - type: string|number|integer|boolean|array|object|null, or a list of them
 *  - properties, required, additionalProperties (bool)
 *  - items, minItems, maxItems
 *  - enum, const
 *  - minLength, maxLength, pattern, minimum, maximum
 *  - format: date (YYYY-MM-DD), date-time, time, email
 *
 * Errors are human-readable and include a JSON path ("$.passengers[0].name").
 */
final class SchemaValidator
{
    private const TYPES = ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'];

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> empty when valid
     */
    public function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];

        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            return [sprintf('%s: must be %s', $path, self::show($schema['const']))];
        }
        if (isset($schema['enum'])) {
            if (!is_array($schema['enum'])) {
                throw new ConfigurationException('Schema "enum" must be an array.');
            }
            if (!in_array($data, $schema['enum'], true)) {
                return [sprintf('%s: must be one of %s, got %s', $path, implode(', ', array_map([self::class, 'show'], $schema['enum'])), self::show($data))];
            }
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            foreach ($types as $type) {
                if (!in_array($type, self::TYPES, true)) {
                    throw new ConfigurationException(sprintf('Unsupported schema type "%s" at %s.', (string) $type, $path));
                }
            }
            $actual = self::typeOf($data);
            $matches = in_array($actual, $types, true)
                || ($actual === 'integer' && in_array('number', $types, true))
                || ($data === [] && in_array('object', $types, true));
            if (!$matches) {
                return [sprintf('%s: expected %s, got %s', $path, implode(' or ', $types), $actual)];
            }
        }

        if (is_string($data)) {
            $length = function_exists('mb_strlen') ? mb_strlen($data, 'UTF-8') : strlen($data);
            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                $errors[] = sprintf('%s: must be at least %d characters', $path, (int) $schema['minLength']);
            }
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                $errors[] = sprintf('%s: must be at most %d characters', $path, (int) $schema['maxLength']);
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $regex = '~' . str_replace('~', '\~', $schema['pattern']) . '~u';
                if (@preg_match($regex, $data) !== 1) {
                    $errors[] = sprintf('%s: does not match pattern %s', $path, $schema['pattern']);
                }
            }
            if (isset($schema['format']) && is_string($schema['format']) && !self::checkFormat($schema['format'], $data)) {
                $errors[] = sprintf('%s: is not a valid %s', $path, $schema['format']);
            }
        }

        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = sprintf('%s: must be >= %s', $path, (string) $schema['minimum']);
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = sprintf('%s: must be <= %s', $path, (string) $schema['maximum']);
            }
        }

        if (is_array($data) && self::isList($data) && self::typeOf($data) === 'array') {
            if (isset($schema['minItems']) && count($data) < (int) $schema['minItems']) {
                $errors[] = sprintf('%s: must have at least %d items', $path, (int) $schema['minItems']);
            }
            if (isset($schema['maxItems']) && count($data) > (int) $schema['maxItems']) {
                $errors[] = sprintf('%s: must have at most %d items', $path, (int) $schema['maxItems']);
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($data as $i => $item) {
                    $errors = array_merge($errors, $this->validate($item, $schema['items'], sprintf('%s[%d]', $path, $i)));
                }
            }
        }

        if (is_array($data) && (self::typeOf($data) === 'object' || ($data === [] && in_array('object', (array) ($schema['type'] ?? []), true)))) {
            /** @var array<string, mixed> $data */
            $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $name) {
                if (!array_key_exists((string) $name, $data)) {
                    $errors[] = sprintf('%s: missing required property "%s"', $path, (string) $name);
                }
            }
            foreach ($data as $name => $value) {
                $name = (string) $name;
                if (isset($properties[$name]) && is_array($properties[$name])) {
                    $errors = array_merge($errors, $this->validate($value, $properties[$name], $path . '.' . $name));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = sprintf('%s: unexpected property "%s"', $path, $name);
                } elseif (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                    $errors = array_merge($errors, $this->validate($value, $schema['additionalProperties'], $path . '.' . $name));
                }
            }
        }

        return $errors;
    }

    /**
     * JSON type of a decoded value. An empty PHP array counts as both an empty
     * array and an empty object, so it is reported as "array" here and
     * accepted for "object" by the caller.
     */
    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => floor($value) === $value && abs($value) < 2 ** 53 ? 'integer' : 'number',
            is_string($value) => 'string',
            is_array($value) => self::isList($value) ? 'array' : 'object',
            default => 'unknown',
        };
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        $i = 0;
        foreach ($value as $key => $_) {
            if ($key !== $i++) {
                return false;
            }
        }

        return true;
    }

    private static function checkFormat(string $format, string $value): bool
    {
        switch ($format) {
            case 'date':
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                return $date !== false && $date->format('Y-m-d') === $value;
            case 'date-time':
                return preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/i', $value) === 1;
            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) === 1;
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            default:
                return true; // unknown formats are annotations only
        }
    }

    private static function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
