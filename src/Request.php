<?php

declare(strict_types=1);

namespace PhpAiGuard;

use PhpAiGuard\Exception\ConfigurationException;

/**
 * A provider-neutral chat request.
 */
final class Request
{
    /** @var list<Message> */
    public array $messages;

    public Context $context;

    /**
     * @param list<Message>             $messages
     * @param array<string, mixed>|null $jsonSchema  schema for structured output (null = free text)
     * @param string|null               $route       tier name ("cheap") or explicit target ("anthropic:claude-haiku-5-5")
     * @param bool|null                 $cache       null = use the configured default
     * @param bool|string|null          $promptCache provider prompt caching: true, "1h", false, or null for the provider default
     */
    public function __construct(
        array $messages,
        public ?string $system = null,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public bool $json = false,
        public ?array $jsonSchema = null,
        public ?string $route = null,
        ?Context $context = null,
        public ?bool $cache = null,
        public ?int $cacheTtl = null,
        public bool|string|null $promptCache = null
    ) {
        if ($messages === []) {
            throw new ConfigurationException('A request needs at least one message.');
        }
        $this->messages = $messages;
        $this->context = $context ?? new Context();
    }

    public static function fromPrompt(string $prompt): self
    {
        return new self([Message::user($prompt)]);
    }

    /**
     * Builds a request from a prompt (string or list of messages) and an options array
     * as accepted by AiGuard::chat().
     *
     * @param string|list<Message|array{role: string, content: string}> $prompt
     * @param array<string, mixed>                                       $options
     */
    public static function fromOptions(string|array $prompt, array $options = [], ?Context $context = null): self
    {
        $messages = [];
        if (is_string($prompt)) {
            $messages[] = Message::user($prompt);
        } else {
            foreach ($prompt as $message) {
                if ($message instanceof Message) {
                    $messages[] = $message;
                } elseif (is_array($message) && isset($message['role'], $message['content'])) {
                    $messages[] = new Message((string) $message['role'], (string) $message['content']);
                } else {
                    throw new ConfigurationException('Each message must be a Message or an array with "role" and "content".');
                }
            }
        }

        $known = ['system', 'max_tokens', 'temperature', 'tier', 'model', 'user', 'tenant', 'feature', 'cache', 'cache_ttl', 'prompt_cache', 'json', 'schema'];
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new ConfigurationException('Unknown option(s): ' . implode(', ', $unknown) . '. Known options: ' . implode(', ', $known) . '.');
        }

        $context = ($context ?? new Context())->with(
            isset($options['tenant']) ? (string) $options['tenant'] : null,
            isset($options['user']) ? (string) $options['user'] : null,
            isset($options['feature']) ? (string) $options['feature'] : null
        );

        $route = null;
        if (isset($options['model'])) {
            $route = (string) $options['model'];
        } elseif (isset($options['tier'])) {
            $route = (string) $options['tier'];
        }

        $schema = $options['schema'] ?? null;
        if ($schema !== null && !is_array($schema)) {
            throw new ConfigurationException('The "schema" option must be an array.');
        }
        $promptCache = $options['prompt_cache'] ?? null;
        if ($promptCache !== null && !is_bool($promptCache) && !is_string($promptCache)) {
            throw new ConfigurationException('The "prompt_cache" option must be true, false or "1h".');
        }

        return new self(
            $messages,
            isset($options['system']) ? (string) $options['system'] : null,
            isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
            isset($options['temperature']) ? (float) $options['temperature'] : null,
            (bool) ($options['json'] ?? ($schema !== null)),
            $schema,
            $route,
            $context,
            isset($options['cache']) ? (bool) $options['cache'] : null,
            isset($options['cache_ttl']) ? (int) $options['cache_ttl'] : null,
            $promptCache
        );
    }

    /**
     * All text that will be sent: used for token estimation and redaction checks.
     */
    public function allText(): string
    {
        $text = (string) $this->system;
        foreach ($this->messages as $message) {
            $text .= "\n" . $message->content;
        }

        return $text;
    }

    /**
     * @param list<Message> $messages
     */
    public function withMessages(array $messages): self
    {
        $clone = clone $this;
        $clone->messages = $messages;

        return $clone;
    }

    public function withSystem(?string $system): self
    {
        $clone = clone $this;
        $clone->system = $system;

        return $clone;
    }
}
