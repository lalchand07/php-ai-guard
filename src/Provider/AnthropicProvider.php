<?php

declare(strict_types=1);

namespace PhpAiGuard\Provider;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\ProviderUnavailableException;
use PhpAiGuard\Http\HttpClientInterface;
use PhpAiGuard\Request;
use PhpAiGuard\Usage;

/**
 * Anthropic Messages API: POST /v1/messages.
 *
 * @see https://platform.claude.com/docs/en/api/messages
 * @see https://platform.claude.com/docs/en/build-with-claude/prompt-caching
 */
final class AnthropicProvider extends AbstractHttpProvider
{
    public const DEFAULT_BASE_URL = 'https://api.anthropic.com';
    public const API_VERSION = '2023-06-01';

    /**
     * @param array<string, string> $extraHeaders
     * @param bool|string           $promptCache  default prompt caching: false, true (5 minutes) or "1h"
     */
    public function __construct(
        HttpClientInterface $http,
        string $apiKey,
        string $name = 'anthropic',
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 30.0,
        bool $allowsPersonalData = true,
        string $jsonMode = JsonMode::PROMPT,
        array $extraHeaders = [],
        private bool|string $promptCache = false,
        private string $apiVersion = self::API_VERSION,
        float $connectTimeout = 10.0
    ) {
        if ($apiKey === '') {
            throw new ConfigurationException(sprintf('Provider "%s": an Anthropic API key is required.', $name));
        }
        if ($jsonMode === JsonMode::OBJECT) {
            throw new ConfigurationException(sprintf('Provider "%s": Anthropic supports json_mode "prompt" or "native", not "object".', $name));
        }
        parent::__construct($name, $http, $apiKey, $baseUrl, $timeout, $allowsPersonalData, $jsonMode, $extraHeaders, $connectTimeout);
    }

    public function usesPromptCache(Request $request): bool
    {
        $setting = $request->promptCache ?? $this->promptCache;

        return $setting !== false && $setting !== '';
    }

    public function complete(Request $request, string $model): ProviderResult
    {
        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens ?? 1024,
            'messages' => array_map(static fn ($m): array => $m->toArray(), $request->messages),
        ];
        if ($request->system !== null && $request->system !== '') {
            $body['system'] = $request->system;
        }
        // Newer models only accept the default temperature, so it is only sent when set explicitly.
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($this->usesPromptCache($request)) {
            $setting = $request->promptCache ?? $this->promptCache;
            $body['cache_control'] = $setting === '1h' ? ['type' => 'ephemeral', 'ttl' => '1h'] : ['type' => 'ephemeral'];
        }
        if ($request->json && $request->jsonSchema !== null && $this->jsonMode === JsonMode::NATIVE) {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $request->jsonSchema]];
        }

        $data = $this->postJson($this->baseUrl . '/v1/messages', [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
        ], $body);

        if (!isset($data['content']) || !is_array($data['content'])) {
            throw new ProviderUnavailableException($this->name . ' returned a response without content.', $this->name, 200, 'malformed_response');
        }
        $text = '';
        foreach ($data['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
        }

        $uncached = self::int($data, 'usage', 'input_tokens');
        $read = self::int($data, 'usage', 'cache_read_input_tokens');
        $write = self::int($data, 'usage', 'cache_creation_input_tokens');
        $usage = new Usage($uncached + $read + $write, self::int($data, 'usage', 'output_tokens'), $read, $write);

        return new ProviderResult(
            $text,
            $usage,
            isset($data['stop_reason']) && is_string($data['stop_reason']) ? $data['stop_reason'] : null,
            $data,
            isset($data['model']) && is_string($data['model']) ? $data['model'] : $model
        );
    }
}
