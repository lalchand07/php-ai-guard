<?php

declare(strict_types=1);

namespace PhpAiGuard\Http;

final class HttpResponse
{
    /** @var array<string, string> lower-cased header names */
    public array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(public int $status, public string $body, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }
}
