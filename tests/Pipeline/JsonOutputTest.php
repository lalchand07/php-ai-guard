<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Pipeline;

use PhpAiGuard\Exception\JsonValidationException;
use PhpAiGuard\Tests\TestCase;

final class JsonOutputTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'required' => ['sentiment', 'score'],
        'additionalProperties' => false,
        'properties' => [
            'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
            'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        ],
    ];

    public function testReturnsValidatedArray(): void
    {
        $this->http->queue(self::anthropicText("```json\n{\"sentiment\": \"positive\", \"score\": 0.9}\n```"));

        $data = $this->guard()->json('I love it', self::SCHEMA);

        self::assertSame(['sentiment' => 'positive', 'score' => 0.9], $data);
        $body = $this->http->lastRequestJson();
        self::assertStringContainsString('JSON schema', $body['system']);
        self::assertStringContainsString('"enum":["positive","neutral","negative"]', $body['system']);
    }

    public function testRetriesOnceWithTheValidationErrors(): void
    {
        $this->http
            ->queue(self::anthropicText('{"sentiment": "happy", "score": 2}', 100, 10))
            ->queue(self::anthropicText('{"sentiment": "positive", "score": 1}', 150, 10));

        $response = $this->guard(['rate_limits' => ['user' => 1]])->forUser('u')->jsonResponse('I love it', self::SCHEMA);

        self::assertSame(['sentiment' => 'positive', 'score' => 1], $response->json);
        self::assertCount(2, $this->http->requests);
        $retry = $this->http->lastRequestJson();
        self::assertSame('assistant', $retry['messages'][1]['role']);
        self::assertSame('{"sentiment": "happy", "score": 2}', $retry['messages'][1]['content']);
        self::assertStringContainsString('$.sentiment: must be one of', $retry['messages'][2]['content']);
        self::assertStringContainsString('$.score: must be <= 1', $retry['messages'][2]['content']);
        // Cost and usage cover both calls; the retry does not count against the rate limit.
        self::assertSame(250, $response->usage->inputTokens);
        self::assertEqualsWithDelta((100 + 150 + 2 * 10 * 2) / 1_000_000, $response->cost, 1e-12);
    }

    public function testThrowsAfterSecondInvalidAnswer(): void
    {
        $this->http->queue(self::anthropicText('Sure! Here you go.'))->queue(self::anthropicText('{"sentiment": "positive"}'));

        try {
            $this->guard()->json('I love it', self::SCHEMA);
            self::fail('Expected JsonValidationException');
        } catch (JsonValidationException $e) {
            self::assertSame(['$: missing required property "score"'], $e->getErrors());
            self::assertSame('{"sentiment": "positive"}', $e->getRawText());
            self::assertStringContainsString('missing required property "score"', $e->getMessage());
        }
        self::assertStringContainsString('not valid JSON', $this->http->lastRequestJson()['messages'][2]['content']);
    }

    public function testNativeJsonModeSendsSchemaToProvider(): void
    {
        $this->http->queue(self::geminiText('{"sentiment":"neutral","score":0.5}'));

        $data = $this->guard()->json('meh', self::SCHEMA, ['model' => 'gemini:gemini-3.8-flash']);

        self::assertSame('neutral', $data['sentiment']);
        self::assertSame(self::SCHEMA, $this->http->lastRequestJson()['generationConfig']['responseFormat']['text']['schema']);
    }
}
