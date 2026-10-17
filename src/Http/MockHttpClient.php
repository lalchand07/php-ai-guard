<?php

declare(strict_types=1);

namespace PhpAiGuard\Http;

/**
 * Replays queued responses; records every request. For tests (yours and ours).
 *
 *     $http = new MockHttpClient();
 *     $http->queueJson(200, ['content' => [...]]);
 */
final class MockHttpClient implements HttpClientInterface
{
    /** @var list<HttpResponse|\Throwable|callable(HttpRequest): HttpResponse> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    /**
     * @param HttpResponse|\Throwable|callable(HttpRequest): HttpResponse $response
     */
    public function queue(HttpResponse|\Throwable|callable $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    public function queueJson(int $status, array $body, array $headers = []): self
    {
        return $this->queue(new HttpResponse($status, (string) json_encode($body), $headers + ['content-type' => 'application/json']));
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \LogicException('MockHttpClient: no response queued for ' . $request->method . ' ' . $request->url);
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next instanceof HttpResponse) {
            return $next;
        }

        return $next($request);
    }

    public function remaining(): int
    {
        return count($this->queue);
    }

    public function lastRequest(): ?HttpRequest
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    /**
     * @return array<mixed>
     */
    public function lastRequestJson(): array
    {
        $request = $this->lastRequest();
        $data = $request === null ? null : json_decode($request->body, true);

        return is_array($data) ? $data : [];
    }
}
