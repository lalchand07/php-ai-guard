<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Provider;

use PhpAiGuard\AiGuardFactory;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\InvalidRequestException;
use PhpAiGuard\Exception\ProviderUnavailableException;
use PhpAiGuard\Http\HttpResponse;
use PhpAiGuard\Provider\JsonMode;
use PhpAiGuard\Provider\OpenAiCompatibleProvider;
use PhpAiGuard\Request;
use PhpAiGuard\Tests\TestCase;

final class OpenAiCompatibleProviderTest extends TestCase
{
    public function testBuildsChatCompletionsRequest(): void
    {
        $this->http->queue(self::fixtureResponse('openai/chat_completion'));
        $provider = new OpenAiCompatibleProvider($this->http, 'sk-secret', 'https://api.openai.com/v1/', 'openai', 30.0, true, JsonMode::NATIVE, [], 'max_completion_tokens');

        $result = $provider->complete(Request::fromOptions('Write a haiku', ['system' => 'Poet', 'max_tokens' => 50]), 'gpt-6-astra');

        $sent = $this->http->lastRequest();
        self::assertNotNull($sent);
        self::assertSame('https://api.openai.com/v1/chat/completions', $sent->url);
        self::assertSame('Bearer sk-secret', $sent->headers['Authorization']);
        $body = $this->http->lastRequestJson();
        self::assertSame([['role' => 'system', 'content' => 'Poet'], ['role' => 'user', 'content' => 'Write a haiku']], $body['messages']);
        self::assertSame(50, $body['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $body);

        self::assertStringStartsWith('Mind of circuits hum', $result->text);
        self::assertSame(13, $result->usage->inputTokens);
        self::assertSame(18, $result->usage->outputTokens);
        self::assertSame('stop', $result->finishReason);
    }

    public function testJsonModes(): void
    {
        $schema = ['type' => 'object'];
        $this->http->queue(self::openAiText('{}'))->queue(self::openAiText('{}'))->queue(self::openAiText('{}'));

        (new OpenAiCompatibleProvider($this->http, 'k', 'https://x.test/v1', 'p', 30.0, true, JsonMode::NATIVE))
            ->complete(Request::fromOptions('x', ['schema' => $schema]), 'm');
        self::assertSame(['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'schema' => $schema, 'strict' => false]], $this->http->lastRequestJson()['response_format']);

        (new OpenAiCompatibleProvider($this->http, 'k', 'https://x.test/v1', 'p', 30.0, true, JsonMode::OBJECT))
            ->complete(Request::fromOptions('x', ['schema' => $schema]), 'm');
        self::assertSame(['type' => 'json_object'], $this->http->lastRequestJson()['response_format']);

        (new OpenAiCompatibleProvider($this->http, 'k', 'https://x.test/v1', 'p', 30.0, true, JsonMode::PROMPT))
            ->complete(Request::fromOptions('x', ['schema' => $schema]), 'm');
        self::assertArrayNotHasKey('response_format', $this->http->lastRequestJson());
    }

    public function testPresets(): void
    {
        $providers = AiGuardFactory::providers([
            'groq' => ['api_key' => 'g'],
            'openrouter' => ['api_key' => 'o', 'headers' => ['HTTP-Referer' => 'https://example.com', 'X-OpenRouter-Title' => 'My App']],
            'nvidia' => ['api_key' => 'n'],
            'local' => ['type' => 'ollama'],
            'openai' => ['api_key' => 'sk'],
        ], $this->http);

        $expect = [
            'groq' => ['https://api.groq.com/openai/v1/chat/completions', 'max_completion_tokens'],
            'openrouter' => ['https://openrouter.ai/api/v1/chat/completions', 'max_tokens'],
            'nvidia' => ['https://integrate.api.nvidia.com/v1/chat/completions', 'max_tokens'],
            'local' => ['http://localhost:11434/v1/chat/completions', 'max_tokens'],
            'openai' => ['https://api.openai.com/v1/chat/completions', 'max_completion_tokens'],
        ];
        foreach ($expect as $name => [$url, $param]) {
            $this->http->queue(self::openAiText('ok'));
            $providers[$name]->complete(Request::fromPrompt('hi'), 'some-model');
            $sent = $this->http->lastRequest();
            self::assertNotNull($sent);
            self::assertSame($url, $sent->url, $name);
            self::assertArrayHasKey($param, $this->http->lastRequestJson(), $name);
            if ($name === 'local') {
                self::assertArrayNotHasKey('Authorization', $sent->headers, 'Ollama needs no key');
            }
            if ($name === 'openrouter') {
                self::assertSame('My App', $sent->headers['X-OpenRouter-Title']);
            }
        }
    }

    public function testMissingKeyForHostedPresetFailsFast(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('api_key');
        AiGuardFactory::providers(['groq' => ['api_key' => '']], $this->http);
    }

    public function testOpenRouterCacheUsage(): void
    {
        $this->http->queue(self::fixtureResponse('openrouter/chat_completion'));
        $provider = new OpenAiCompatibleProvider($this->http, 'k', 'https://openrouter.ai/api/v1', 'openrouter');

        $result = $provider->complete(Request::fromPrompt('Say hello in French'), 'meta-llama/llama-3.3-70b-instruct');

        self::assertSame('Bonjour !', $result->text);
        self::assertSame(120, $result->usage->inputTokens);
        self::assertSame(100, $result->usage->cacheReadTokens);
    }

    public function testErrorsAndGatewayErrorsWithStatus200(): void
    {
        $provider = new OpenAiCompatibleProvider($this->http, 'k', 'https://x.test/v1');
        $this->http->queue(self::fixtureResponse('openai/error_invalid_request', 400));
        try {
            $provider->complete(Request::fromPrompt('x'), 'm');
            self::fail('Expected exception');
        } catch (InvalidRequestException $e) {
            self::assertSame('invalid_request_error', $e->getErrorType());
            self::assertStringContainsString('max_completion_tokens', $e->getMessage());
        }

        $this->http->queue(new HttpResponse(200, '{"error":{"code":502,"message":"Upstream provider error"}}'));
        try {
            $provider->complete(Request::fromPrompt('x'), 'm');
            self::fail('Expected exception');
        } catch (ProviderUnavailableException $e) {
            self::assertTrue($e->isRetryable());
        }

        $this->http->queue(new HttpResponse(502, '<html>Bad gateway</html>'));
        $this->expectException(ProviderUnavailableException::class);
        $this->expectExceptionMessage('Bad gateway');
        $provider->complete(Request::fromPrompt('x'), 'm');
    }

    public function testContentPartsArray(): void
    {
        $this->http->queue(new HttpResponse(200, (string) json_encode([
            'choices' => [['message' => ['content' => [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']]], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])));
        $provider = new OpenAiCompatibleProvider($this->http, 'k', 'https://x.test/v1');

        self::assertSame('ab', $provider->complete(Request::fromPrompt('x'), 'm')->text);
    }
}
