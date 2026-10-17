<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Provider;

use PhpAiGuard\Exception\ContentBlockedException;
use PhpAiGuard\Exception\InvalidRequestException;
use PhpAiGuard\Exception\ProviderRateLimitException;
use PhpAiGuard\Message;
use PhpAiGuard\Provider\GeminiProvider;
use PhpAiGuard\Provider\JsonMode;
use PhpAiGuard\Request;
use PhpAiGuard\Tests\TestCase;

final class GeminiProviderTest extends TestCase
{
    public function testBuildsGenerateContentRequest(): void
    {
        $this->http->queue(self::fixtureResponse('gemini/generate_content'));
        $provider = new GeminiProvider($this->http, 'gemini-secret');

        $request = new Request([Message::user('List cookies'), Message::assistant('Sure'), Message::user('Two please')], 'You are a baker.', 200, 0.3);
        $provider->complete($request, 'gemini-3.8-flash');

        $sent = $this->http->lastRequest();
        self::assertNotNull($sent);
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', $sent->url);
        self::assertSame('gemini-secret', $sent->headers['x-goog-api-key']);
        self::assertStringNotContainsString('gemini-secret', $sent->url, 'the key goes in a header, never the URL');

        $body = $this->http->lastRequestJson();
        self::assertSame([
            ['role' => 'user', 'parts' => [['text' => 'List cookies']]],
            ['role' => 'model', 'parts' => [['text' => 'Sure']]],
            ['role' => 'user', 'parts' => [['text' => 'Two please']]],
        ], $body['contents']);
        self::assertSame(['parts' => [['text' => 'You are a baker.']]], $body['systemInstruction']);
        self::assertSame(['maxOutputTokens' => 200, 'temperature' => 0.3], $body['generationConfig']);
    }

    public function testParsesTextSkippingThoughtsAndUsage(): void
    {
        $this->http->queue(self::fixtureResponse('gemini/generate_content'));
        $provider = new GeminiProvider($this->http, 'key');

        $result = $provider->complete(Request::fromPrompt('List cookies'), 'models/gemini-3.8-flash');

        self::assertSame("1. Chocolate chip cookies\n2. Oatmeal raisin cookies", $result->text);
        self::assertSame('STOP', $result->finishReason);
        self::assertSame(10, $result->usage->inputTokens);
        self::assertSame(15 + 5, $result->usage->outputTokens, 'thinking tokens are billed as output');
        self::assertSame(4, $result->usage->cacheReadTokens);
        self::assertStringEndsWith('/models/gemini-3.8-flash:generateContent', (string) $this->http->lastRequest()?->url);
    }

    public function testNativeJsonUsesResponseFormat(): void
    {
        $this->http->queue(self::geminiText('{}'));
        $provider = new GeminiProvider($this->http, 'key');
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $provider->complete(Request::fromOptions('x', ['schema' => $schema]), 'gemini-3.8-flash');

        self::assertSame(['text' => ['mimeType' => 'APPLICATION_JSON', 'schema' => $schema]], $this->http->lastRequestJson()['generationConfig']['responseFormat']);
    }

    public function testLegacyJsonFields(): void
    {
        $this->http->queue(self::geminiText('{}'));
        $provider = new GeminiProvider($this->http, 'key', 'gemini', GeminiProvider::DEFAULT_BASE_URL, 30.0, true, JsonMode::NATIVE, [], true);
        $schema = ['type' => 'object'];

        $provider->complete(Request::fromOptions('x', ['schema' => $schema]), 'gemini-3.8-flash');

        $config = $this->http->lastRequestJson()['generationConfig'];
        self::assertSame('application/json', $config['responseMimeType']);
        self::assertSame($schema, $config['responseJsonSchema']);
    }

    public function testBlockedPrompt(): void
    {
        $this->http->queue(self::fixtureResponse('gemini/prompt_blocked'));
        $provider = new GeminiProvider($this->http, 'key');

        $this->expectException(ContentBlockedException::class);
        $this->expectExceptionMessage('SAFETY');
        $provider->complete(Request::fromPrompt('bad'), 'gemini-3.8-flash');
    }

    public function testErrorMapping(): void
    {
        $this->http->queue(self::fixtureResponse('gemini/error_resource_exhausted', 429));
        $this->http->queue(self::fixtureResponse('gemini/error_invalid_argument', 400));
        $provider = new GeminiProvider($this->http, 'key');

        try {
            $provider->complete(Request::fromPrompt('x'), 'gemini-3.8-flash');
            self::fail('Expected exception');
        } catch (ProviderRateLimitException $e) {
            self::assertSame('RESOURCE_EXHAUSTED', $e->getErrorType());
        }

        $this->expectException(InvalidRequestException::class);
        $provider->complete(Request::fromPrompt('x'), 'gemini-3.8-flash');
    }
}
