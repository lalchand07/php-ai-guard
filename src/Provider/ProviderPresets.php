<?php

declare(strict_types=1);

namespace PhpAiGuard\Provider;

/**
 * Defaults for well-known OpenAI-compatible services. Every value can be
 * overridden in the provider config.
 */
final class ProviderPresets
{
    /**
     * @var array<string, array{base_url: string, max_tokens_param: string, json_mode: string, requires_key: bool}>
     */
    public const PRESETS = [
        // https://github.com/openai/openai-openapi — max_tokens is deprecated in favour of max_completion_tokens.
        'openai' => ['base_url' => 'https://api.openai.com/v1', 'max_tokens_param' => 'max_completion_tokens', 'json_mode' => JsonMode::NATIVE, 'requires_key' => true],
        // https://console.groq.com/docs/openai
        'groq' => ['base_url' => 'https://api.groq.com/openai/v1', 'max_tokens_param' => 'max_completion_tokens', 'json_mode' => JsonMode::OBJECT, 'requires_key' => true],
        // https://openrouter.ai/docs/api-reference/overview
        'openrouter' => ['base_url' => 'https://openrouter.ai/api/v1', 'max_tokens_param' => 'max_tokens', 'json_mode' => JsonMode::OBJECT, 'requires_key' => true],
        // NVIDIA-hosted NIM endpoints are OpenAI-compatible; JSON mode left at "prompt" as it was not verified.
        'nvidia' => ['base_url' => 'https://integrate.api.nvidia.com/v1', 'max_tokens_param' => 'max_tokens', 'json_mode' => JsonMode::PROMPT, 'requires_key' => true],
        // https://github.com/ollama/ollama/blob/main/docs/api/openai-compatibility.mdx
        'ollama' => ['base_url' => 'http://localhost:11434/v1', 'max_tokens_param' => 'max_tokens', 'json_mode' => JsonMode::OBJECT, 'requires_key' => false],
    ];

    private function __construct()
    {
    }

    /**
     * @return array{base_url: string, max_tokens_param: string, json_mode: string, requires_key: bool}|null
     */
    public static function get(string $name): ?array
    {
        return self::PRESETS[$name] ?? null;
    }
}
