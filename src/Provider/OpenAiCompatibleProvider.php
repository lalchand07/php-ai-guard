<?php

declare(strict_types=1);

namespace PhpAiGuard\Provider;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\ProviderUnavailableException;
use PhpAiGuard\Http\HttpClientInterface;
use PhpAiGuard\Request;
use PhpAiGuard\Usage;

/**
 * Any OpenAI-compatible Chat Completions endpoint: POST {base_url}/chat/completions.
 * Works with OpenAI, Groq, OpenRouter, NVIDIA NIM, Ollama, LM Studio, vLLM, ...
 * See ProviderPresets for ready-made base URLs and settings.
 */
final class OpenAiCompatibleProvider extends AbstractHttpProvider
{
    /**
     * @param array<string, string> $extraHeaders
     * @param string                $maxTokensParam "max_completion_tokens" (OpenAI, Groq) or "max_tokens" (most others)
     */
    public function __construct(
        HttpClientInterface $http,
        string $apiKey,
        string $baseUrl,
        string $name = 'openai',
        float $timeout = 30.0,
        bool $allowsPersonalData = true,
        string $jsonMode = JsonMode::OBJECT,
        array $extraHeaders = [],
        private string $maxTokensParam = 'max_tokens',
        float $connectTimeout = 10.0
    ) {
        if ($baseUrl === '') {
            throw new ConfigurationException(sprintf('Provider "%s": base_url is required for OpenAI-compatible providers.', $name));
        }
        if (!in_array($maxTokensParam, ['max_tokens', 'max_completion_tokens'], true)) {
            throw new ConfigurationException(sprintf('Provider "%s": max_tokens_param must be "max_tokens" or "max_completion_tokens".', $name));
        }
        parent::__construct($name, $http, $apiKey, $baseUrl, $timeout, $allowsPersonalData, $jsonMode, $extraHeaders, $connectTimeout);
    }

    public function complete(Request $request, string $model): ProviderResult
    {
        $messages = [];
        if ($request->system !== null && $request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        foreach ($request->messages as $message) {
            $messages[] = $message->toArray();
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
            $this->maxTokensParam => $request->maxTokens ?? 1024,
        ];
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($request->json && $this->jsonMode !== JsonMode::PROMPT) {
            if ($this->jsonMode === JsonMode::NATIVE && $request->jsonSchema !== null) {
                $body['response_format'] = [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'response', 'schema' => $request->jsonSchema, 'strict' => false],
                ];
            } else {
                $body['response_format'] = ['type' => 'json_object'];
            }
        }

        $headers = [];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $data = $this->postJson($this->baseUrl . '/chat/completions', $headers, $body);

        // Some gateways (e.g. OpenRouter) can report upstream errors with HTTP 200.
        if (isset($data['error']) && !isset($data['choices'])) {
            $message = is_array($data['error']) && isset($data['error']['message']) && is_string($data['error']['message']) ? $data['error']['message'] : 'unknown error';
            throw new ProviderUnavailableException($this->name . ' returned an error: ' . $message, $this->name, 200, 'upstream_error');
        }
        $choice = isset($data['choices'][0]) && is_array($data['choices'][0]) ? $data['choices'][0] : null;
        if ($choice === null) {
            throw new ProviderUnavailableException($this->name . ' returned no choices.', $this->name, 200, 'malformed_response');
        }

        $content = $choice['message']['content'] ?? '';
        $text = '';
        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }

        $usage = new Usage(
            self::int($data, 'usage', 'prompt_tokens'),
            self::int($data, 'usage', 'completion_tokens'),
            self::int($data, 'usage', 'prompt_tokens_details', 'cached_tokens'),
            self::int($data, 'usage', 'prompt_tokens_details', 'cache_write_tokens')
        );

        return new ProviderResult(
            $text,
            $usage,
            isset($choice['finish_reason']) && is_string($choice['finish_reason']) ? $choice['finish_reason'] : null,
            $data,
            isset($data['model']) && is_string($data['model']) ? $data['model'] : $model
        );
    }
}
