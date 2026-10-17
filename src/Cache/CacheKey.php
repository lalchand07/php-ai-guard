<?php

declare(strict_types=1);

namespace PhpAiGuard\Cache;

use PhpAiGuard\Request;
use PhpAiGuard\Routing\Target;

/**
 * sha256 over everything that changes the answer: provider, model and the
 * request as sent (after redaction). Budget context is deliberately excluded,
 * so different users asking the same thing share one cached answer.
 */
final class CacheKey
{
    public static function for(Target $target, Request $request): string
    {
        $payload = [
            'v' => 1,
            'provider' => $target->provider,
            'model' => $target->model,
            'system' => $request->system,
            'messages' => array_map(static fn ($m): array => $m->toArray(), $request->messages),
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'json' => $request->json,
            'schema' => $request->jsonSchema,
        ];

        return 'ai_guard:' . hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
