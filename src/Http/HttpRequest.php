<?php

declare(strict_types=1);

namespace PhpAiGuard\Http;

/**
 * A minimal HTTP request. Header values may contain secrets: never log them.
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public string $body = '',
        public float $timeout = 30.0,
        public float $connectTimeout = 10.0
    ) {
    }

    /**
     * Hides header values (API keys) from var_dump()/print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => array_map(static fn (): string => '***', $this->headers),
            'body' => strlen($this->body) . ' bytes',
            'timeout' => $this->timeout,
        ];
    }
}
