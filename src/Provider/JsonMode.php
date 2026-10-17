<?php

declare(strict_types=1);

namespace PhpAiGuard\Provider;

/**
 * How a provider is asked for JSON output. The schema is always also described
 * in the system prompt and always validated locally, whatever the mode.
 */
final class JsonMode
{
    /** Instructions in the prompt only. Works with every model. */
    public const PROMPT = 'prompt';
    /** Provider JSON mode without a schema (e.g. OpenAI "json_object"). */
    public const OBJECT = 'object';
    /** Provider-native schema-constrained output (structured outputs). */
    public const NATIVE = 'native';

    public const ALL = [self::PROMPT, self::OBJECT, self::NATIVE];

    private function __construct()
    {
    }
}
