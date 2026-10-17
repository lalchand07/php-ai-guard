<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Provider;

use PhpAiGuard\Exception\AuthenticationException;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\InvalidRequestException;
use PhpAiGuard\Exception\ProviderRateLimitException;
use PhpAiGuard\Exception\ProviderTimeoutException;
use PhpAiGuard\Exception\ProviderUnavailableException;
use PhpAiGuard\Message;
use PhpAiGuard\Provider\AnthropicProvider;
use PhpAiGuard\Provider\JsonMode;
use PhpAiGuard\Request;
use PhpAiGuard\Tests\TestCase;

final class AnthropicProviderTest extends TestCase
{
    public function testBuildsMessagesRequest(): void
    {
        $this->http->queue(self::fixtureResponse('anthropic/message_simple'));
        $provider = new AnthropicProvider($this->http, 'sk-ant-secret');

        $request = new Request([Message::user('Hello'), Message::assistant('Hi'), Message::user('How are you?')], 'Be brief.', 300);
        $provider->complete($request, 'claude-haiku-5-5');

        $sent = $this->http->lastRequest();
        self::assertNotNull($sent);
        self::assertSame('POST', $sent->method);
        self::assertSame('https://api.anthropic.com/v1/messages', $sent->url);
        self::assertSame('sk-ant-secret', $sent->headers['x-api-key']);
        self::assertSame('2023-06-01', $sent->headers['anthropic-version']);
        self::assertSame('application/json', $sent->headers['Content-Type']);
        self::assertSame(30.0, $sent->timeout);

        $body = $this->http->lastRequestJson();
        self::assertSame('claude-haiku-5-5', $body['model']);
        self::assertSame(300, $body['max_tokens']);
        self::assertSame('Be brief.', $body['system']);
        self::assertSame([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi'],
            ['role' => 'user', 'content' => 'How are you?'],
        ], $body['messages']);
        self::assertArrayNotHasKey('temperature', $body, 'temperature is only sent when set (newer models reject non-default values)');
        self::assertArrayNotHasKey('cache_control', $body);
        self::assertArrayNotHasKey('output_config', $body);
    }

    public function testParsesTextAndUsageIncludingPromptCache(): void
    {
        $this->http->queue(self::fixtureResponse('anthropic/message'));
        $provider = new AnthropicProvider($this->http, 'key');

        $result = $provider->complete(Request::fromPrompt('Hi'), 'claude-haiku-5-5');

        self::assertSame('Hi! My name is Claude.', $result->text);
        self::assertSame('end_turn', $result->finishReason);
        // total input = input_tokens + cache_read_input_tokens + cache_creation_input_tokens
        self::assertSame(2095 + 2051 + 2051, $result->usage->inputTokens);
        self::assertSame(503, $result->usage->outputTokens);
        self::assertSame(2051, $result->usage->cacheReadTokens);
        self::assertSame(2051, $result->usage->cacheWriteTokens);
    }

    public function testPromptCachingAndTemperatureAndNativeJson(): void
    {
        $this->http->queue(self::fixtureResponse('anthropic/message_simple'));
        $this->http->queue(self::fixtureResponse('anthropic/message_simple'));
        $provider = new AnthropicProvider($this->http, 'key', 'anthropic', AnthropicProvider::DEFAULT_BASE_URL, 30.0, true, JsonMode::NATIVE, [], true);

        $request = Request::fromOptions('Hi', ['temperature' => 0.2, 'schema' => ['type' => 'object']]);
        self::assertTrue($provider->usesPromptCache($request));
        $provider->complete($request, 'claude-sonnet-5-5');
        $body = $this->http->lastRequestJson();
        self::assertSame(['type' => 'ephemeral'], $body['cache_control']);
        self::assertSame(0.2, $body['temperature']);
        self::assertSame(['format' => ['type' => 'json_schema', 'schema' => ['type' => 'object']]], $body['output_config']);

        $provider->complete(Request::fromOptions('Hi', ['prompt_cache' => '1h']), 'claude-sonnet-5-5');
        self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $this->http->lastRequestJson()['cache_control']);
    }

    public function testOverloadedIsRetryable(): void
    {
        $this->http->queue(self::fixtureResponse('anthropic/error_overloaded', 529));
        $provider = new AnthropicProvider($this->http, 'key');

        try {
            $provider->complete(Request::fromPrompt('Hi'), 'claude-haiku-5-5');
            self::fail('Expected exception');
        } catch (ProviderUnavailableException $e) {
            self::assertTrue($e->isRetryable());
            self::assertSame(529, $e->getStatusCode());
            self::assertSame('overloaded_error', $e->getErrorType());
            self::assertStringContainsString('Overloaded', $e->getMessage());
        }
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $this->http->queue(self::fixtureResponse('anthropic/error_rate_limit', 429, ['retry-after' => '7']));
        $provider = new AnthropicProvider($this->http, 'key');

        try {
            $provider->complete(Request::fromPrompt('Hi'), 'claude-haiku-5-5');
            self::fail('Expected exception');
        } catch (ProviderRateLimitException $e) {
            self::assertTrue($e->isRetryable());
            self::assertSame(7.0, $e->getRetryAfter());
        }
    }

    public function testInvalidRequestIsNotRetryable(): void
    {
        $this->http->queue(self::fixtureResponse('anthropic/error_invalid_request', 400));
        $provider = new AnthropicProvider($this->http, 'key');

        try {
            $provider->complete(Request::fromPrompt('Hi'), 'claude-haiku-5-5');
            self::fail('Expected exception');
        } catch (InvalidRequestException $e) {
            self::assertFalse($e->isRetryable());
            self::assertSame('invalid_request_error', $e->getErrorType());
        }
    }

    public function testAuthenticationErrorNeverLeaksTheKey(): void
    {
        $this->http->queue(self::error(401, 'authentication_error', 'invalid x-api-key'));
        $provider = new AnthropicProvider($this->http, 'sk-ant-very-secret');

        try {
            $provider->complete(Request::fromPrompt('Hi'), 'claude-haiku-5-5');
            self::fail('Expected exception');
        } catch (AuthenticationException $e) {
            self::assertStringNotContainsString('sk-ant-very-secret', $e->getMessage());
        }
        self::assertStringNotContainsString('sk-ant-very-secret', print_r($this->http->lastRequest(), true));
    }

    public function testTransportTimeoutIsWrapped(): void
    {
        $this->http->queue(new ProviderTimeoutException('Request timed out after 30.0 s (api.anthropic.com).'));
        $provider = new AnthropicProvider($this->http, 'key');

        $this->expectException(ProviderTimeoutException::class);
        $this->expectExceptionMessage('anthropic: Request timed out');
        $provider->complete(Request::fromPrompt('Hi'), 'claude-haiku-5-5');
    }

    public function testRejectsObjectJsonModeAndMissingKey(): void
    {
        try {
            new AnthropicProvider($this->http, 'key', 'anthropic', AnthropicProvider::DEFAULT_BASE_URL, 30.0, true, JsonMode::OBJECT);
            self::fail('Expected exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('object', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        new AnthropicProvider($this->http, '');
    }
}
