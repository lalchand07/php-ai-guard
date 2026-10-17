<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests;

use PhpAiGuard\AiGuard;
use PhpAiGuard\Clock\FrozenClock;
use PhpAiGuard\Http\HttpResponse;
use PhpAiGuard\Http\MockHttpClient;
use PhpAiGuard\Retry\RecordingSleeper;
use PhpAiGuard\Storage\InMemoryStorage;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected MockHttpClient $http;
    protected FrozenClock $clock;
    protected RecordingSleeper $sleeper;
    protected InMemoryStorage $storage;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->clock = new FrozenClock('2026-10-17 12:00:00');
        $this->sleeper = new RecordingSleeper();
        $this->storage = new InMemoryStorage();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, string> $headers
     */
    protected static function fixtureResponse(string $name, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, (string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'), $headers + ['content-type' => 'application/json']);
    }

    /** A minimal Anthropic success body. */
    protected static function anthropicText(string $text, int $in = 10, int $out = 5): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
        ]));
    }

    protected static function openAiText(string $text, int $in = 10, int $out = 5): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'test-model',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out],
        ]));
    }

    protected static function geminiText(string $text, int $in = 10, int $out = 5): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode([
            'candidates' => [['content' => ['parts' => [['text' => $text]], 'role' => 'model'], 'finishReason' => 'STOP', 'index' => 0]],
            'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out, 'totalTokenCount' => $in + $out],
            'modelVersion' => 'gemini-3.8-flash',
        ]));
    }

    protected static function error(int $status, string $type = 'api_error', string $message = 'error', array $headers = []): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]), $headers);
    }

    /**
     * A guard with three mocked providers (anthropic, gemini, openai), test
     * pricing ($1 / $2 per 1M tokens) and in-memory storage.
     *
     * @param array<string, mixed> $overrides
     */
    protected function guard(array $overrides = []): AiGuard
    {
        $config = array_replace([
            'providers' => [
                'anthropic' => ['api_key' => 'sk-ant-test-secret'],
                'gemini' => ['api_key' => 'gemini-test-secret'],
                'openai' => ['api_key' => 'sk-openai-test-secret'],
            ],
            'tiers' => [
                'standard' => ['anthropic:claude-haiku-5-5', 'gemini:gemini-3.8-flash', 'openai:gpt-test'],
                'cheap' => ['openai:gpt-test'],
            ],
            'pricing' => [
                'anthropic' => ['claude-haiku-5-5' => ['input' => 1.0, 'output' => 2.0, 'cache_read' => 0.1, 'cache_write' => 1.25]],
                'gemini' => ['gemini-3.8-flash' => ['input' => 1.0, 'output' => 2.0]],
                'openai' => ['gpt-test' => ['input' => 1.0, 'output' => 2.0]],
            ],
            'storage' => $this->storage,
            'http_client' => $this->http,
            'clock' => $this->clock,
            'sleeper' => $this->sleeper,
        ], $overrides);

        return AiGuard::create($config);
    }

    /**
     * @return list<string> hosts of every request sent so far
     */
    protected function requestedHosts(): array
    {
        return array_map(static fn ($r): string => (string) parse_url($r->url, PHP_URL_HOST), $this->http->requests);
    }
}
