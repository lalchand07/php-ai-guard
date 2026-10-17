<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Json;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Json\JsonExtractor;
use PhpAiGuard\Json\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private const TRIP = [
        'type' => 'object',
        'required' => ['passengers', 'legs'],
        'properties' => [
            'passengers' => ['type' => 'array', 'minItems' => 1, 'items' => [
                'type' => 'object',
                'required' => ['name'],
                'additionalProperties' => false,
                'properties' => ['name' => ['type' => 'string', 'minLength' => 2], 'age' => ['type' => ['integer', 'null'], 'minimum' => 0]],
            ]],
            'legs' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['from', 'to', 'date'],
                'properties' => [
                    'from' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                    'to' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                    'date' => ['type' => 'string', 'format' => 'date'],
                    'class' => ['enum' => ['economy', 'business']],
                ],
            ]],
            'notes' => ['type' => 'string', 'maxLength' => 10],
        ],
    ];

    public function testValidDocument(): void
    {
        $data = [
            'passengers' => [['name' => 'Ana', 'age' => 31], ['name' => 'Raj', 'age' => null]],
            'legs' => [['from' => 'DEL', 'to' => 'DXB', 'date' => '2026-11-02', 'class' => 'economy']],
        ];
        self::assertSame([], (new SchemaValidator())->validate($data, self::TRIP));
    }

    public function testCollectsReadableErrorsWithPaths(): void
    {
        $data = [
            'passengers' => [['name' => 'A', 'age' => -1, 'seat' => '12A'], ['age' => '30']],
            'legs' => [['from' => 'Delhi', 'to' => 'DXB', 'date' => '2026-02-30', 'class' => 'first']],
            'notes' => 'far too long text',
        ];

        $errors = (new SchemaValidator())->validate($data, self::TRIP);

        self::assertSame([
            '$.passengers[0].name: must be at least 2 characters',
            '$.passengers[0].age: must be >= 0',
            '$.passengers[0]: unexpected property "seat"',
            '$.passengers[1]: missing required property "name"',
            '$.passengers[1].age: expected integer or null, got string',
            '$.legs[0].from: does not match pattern ^[A-Z]{3}$',
            '$.legs[0].date: is not a valid date',
            '$.legs[0].class: must be one of "economy", "business", got "first"',
            '$.notes: must be at most 10 characters',
        ], $errors);
    }

    public function testTypeEdgeCases(): void
    {
        $v = new SchemaValidator();
        self::assertSame([], $v->validate(3.0, ['type' => 'integer']), '3.0 is an integer in JSON');
        self::assertSame([], $v->validate(3, ['type' => 'number']));
        self::assertSame([], $v->validate([], ['type' => 'object']), 'an empty JSON object decodes to []');
        self::assertSame([], $v->validate([], ['type' => 'array']));
        self::assertSame(['$: expected array, got object'], $v->validate(['a' => 1], ['type' => 'array']));
        self::assertSame(['$: expected object, got array'], $v->validate([1, 2], ['type' => 'object']));
        self::assertSame(['$: must be "x"'], $v->validate('y', ['const' => 'x']));
        self::assertSame(['$: must have at most 1 items'], $v->validate([1, 2], ['type' => 'array', 'maxItems' => 1]));
    }

    public function testUnsupportedTypeIsAConfigError(): void
    {
        $this->expectException(ConfigurationException::class);
        (new SchemaValidator())->validate(1, ['type' => 'int']);
    }

    public function testExtractor(): void
    {
        self::assertSame(['a' => 1], JsonExtractor::extract('{"a":1}')['value']);
        self::assertSame(['a' => 1], JsonExtractor::extract("```json\n{\"a\":1}\n```")['value']);
        self::assertSame([1, 2], JsonExtractor::extract('Here is the list: [1, 2] — enjoy!')['value']);
        self::assertSame(['a' => ['b' => 2]], JsonExtractor::extract('Result: {"a": {"b": 2}}')['value']);
        $bad = JsonExtractor::extract('no json at all');
        self::assertFalse($bad['ok']);
        self::assertStringContainsString('not valid JSON', (string) $bad['error']);
    }
}
