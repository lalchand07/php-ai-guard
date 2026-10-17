<?php

declare(strict_types=1);

namespace PhpAiGuard\Provider;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\ContentBlockedException;
use PhpAiGuard\Exception\ProviderUnavailableException;
use PhpAiGuard\Http\HttpClientInterface;
use PhpAiGuard\Message;
use PhpAiGuard\Request;
use PhpAiGuard\Usage;

/**
 * Google Gemini API: POST /v1beta/models/{model}:generateContent.
 *
 * @see https://ai.google.dev/api/generate-content
 */
final class GeminiProvider extends AbstractHttpProvider
{
    public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const BLOCKED_FINISH_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'];

    /**
     * @param array<string, string> $extraHeaders
     * @param bool                  $legacyJsonFields use responseMimeType/responseJsonSchema instead of responseFormat
     */
    public function __construct(
        HttpClientInterface $http,
        string $apiKey,
        string $name = 'gemini',
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 30.0,
        bool $allowsPersonalData = true,
        string $jsonMode = JsonMode::NATIVE,
        array $extraHeaders = [],
        private bool $legacyJsonFields = false,
        float $connectTimeout = 10.0
    ) {
        if ($apiKey === '') {
            throw new ConfigurationException(sprintf('Provider "%s": a Gemini API key is required.', $name));
        }
        parent::__construct($name, $http, $apiKey, $baseUrl, $timeout, $allowsPersonalData, $jsonMode, $extraHeaders, $connectTimeout);
    }

    public function complete(Request $request, string $model): ProviderResult
    {
        if (!preg_match('~^[A-Za-z0-9._\-/]+$~', $model)) {
            throw new ConfigurationException(sprintf('Invalid Gemini model name "%s".', $model));
        }
        $path = str_starts_with($model, 'models/') || str_starts_with($model, 'tunedModels/') ? $model : 'models/' . $model;

        $contents = [];
        foreach ($request->messages as $message) {
            $contents[] = [
                'role' => $message->role === Message::ASSISTANT ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ];
        }
        $body = ['contents' => $contents];
        if ($request->system !== null && $request->system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $request->system]]];
        }

        $config = ['maxOutputTokens' => $request->maxTokens ?? 1024];
        if ($request->temperature !== null) {
            $config['temperature'] = $request->temperature;
        }
        if ($request->json && $this->jsonMode !== JsonMode::PROMPT) {
            $schema = $this->jsonMode === JsonMode::NATIVE ? $request->jsonSchema : null;
            if ($this->legacyJsonFields) {
                $config['responseMimeType'] = 'application/json';
                if ($schema !== null) {
                    $config['responseJsonSchema'] = $schema;
                }
            } else {
                $format = ['mimeType' => 'APPLICATION_JSON'];
                if ($schema !== null) {
                    $format['schema'] = $schema;
                }
                $config['responseFormat'] = ['text' => $format];
            }
        }
        $body['generationConfig'] = $config;

        $data = $this->postJson($this->baseUrl . '/' . $path . ':generateContent', ['x-goog-api-key' => $this->apiKey], $body);

        $candidate = isset($data['candidates'][0]) && is_array($data['candidates'][0]) ? $data['candidates'][0] : null;
        if ($candidate === null) {
            $reason = $data['promptFeedback']['blockReason'] ?? null;
            if (is_string($reason)) {
                throw new ContentBlockedException(sprintf('%s blocked the prompt (%s).', $this->name, $reason), $this->name, 200, $reason);
            }
            throw new ProviderUnavailableException($this->name . ' returned no candidates.', $this->name, 200, 'malformed_response');
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text']) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }
        $finish = isset($candidate['finishReason']) && is_string($candidate['finishReason']) ? $candidate['finishReason'] : null;
        if ($text === '' && $finish !== null && in_array($finish, self::BLOCKED_FINISH_REASONS, true)) {
            throw new ContentBlockedException(sprintf('%s stopped the response (%s).', $this->name, $finish), $this->name, 200, $finish);
        }

        $usage = new Usage(
            self::int($data, 'usageMetadata', 'promptTokenCount') + self::int($data, 'usageMetadata', 'toolUsePromptTokenCount'),
            self::int($data, 'usageMetadata', 'candidatesTokenCount') + self::int($data, 'usageMetadata', 'thoughtsTokenCount'),
            self::int($data, 'usageMetadata', 'cachedContentTokenCount')
        );

        return new ProviderResult(
            $text,
            $usage,
            $finish,
            $data,
            isset($data['modelVersion']) && is_string($data['modelVersion']) ? $data['modelVersion'] : $model
        );
    }
}
