<?php

declare(strict_types=1);

namespace PhpAiGuard;

use PhpAiGuard\Exception\ConfigurationException;

/**
 * One conversation turn. The system prompt is not a message: set it on the Request.
 */
final class Message
{
    public const USER = 'user';
    public const ASSISTANT = 'assistant';

    public function __construct(public string $role, public string $content)
    {
        if ($role !== self::USER && $role !== self::ASSISTANT) {
            throw new ConfigurationException(sprintf('Message role must be "user" or "assistant", "%s" given.', $role));
        }
    }

    public static function user(string $content): self
    {
        return new self(self::USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ASSISTANT, $content);
    }

    /** @return array{role: string, content: string} */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
