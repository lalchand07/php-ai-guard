<?php

declare(strict_types=1);

namespace PhpAiGuard\Provider;

use PhpAiGuard\Exception\AuthenticationException;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Exception\InvalidRequestException;
use PhpAiGuard\Exception\ProviderException;
use PhpAiGuard\Exception\ProviderNetworkException;
use PhpAiGuard\Exception\ProviderRateLimitException;
use PhpAiGuard\Exception\ProviderTimeoutException;
use PhpAiGuard\Exception\ProviderUnavailableException;
use PhpAiGuard\Http\HttpClientInterface;
use PhpAiGuard\Http\HttpRequest;
use PhpAiGuard\Http\HttpResponse;
use PhpAiGuard\Request;
use PhpAiGuard\Support\Str;

/**
 * Shared plumbing for HTTP/JSON providers: sending, decoding and mapping HTTP
 * errors onto the exception hierarchy (retryable vs. not).
 */
abstract class AbstractHttpProvider implements ProviderInterface
{
    /**
     * @param array<string, string> $extraHeaders
     */
    public function __construct(
        protected string $name,
        protected HttpClientInterface $http,
        protected string $apiKey,
        protected string $baseUrl,
        protected float $timeout = 30.0,
        protected bool $allowsPersonalData = true,
        protected string $jsonMode = JsonMode::PROMPT,
        protected array $extraHeaders = [],
        protected float $connectTimeout = 10.0
    ) {
        if (!in_array($jsonMode, JsonMode::ALL, true)) {
            throw new ConfigurationException(sprintf('Provider "%s": json_mode must be one of %s.', $name, implode(', ', JsonMode::ALL)));
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function allowsPersonalData(): bool
    {
        return $this->allowsPersonalData;
    }

    public function usesPromptCache(Request $request): bool
    {
        return false;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     *
     * @return array<string, mixed>
     */
    protected function postJson(string $url, array $headers, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new InvalidRequestException('Could not encode the request as JSON: ' . json_last_error_msg(), $this->name);
        }
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers + $this->extraHeaders;

        try {
            $response = $this->http->send(new HttpRequest('POST', $url, $headers, $payload, $this->timeout, $this->connectTimeout));
        } catch (ProviderTimeoutException $e) {
            throw new ProviderTimeoutException($this->name . ': ' . $e->getMessage(), $this->name, null, 'timeout', $e);
        } catch (ProviderNetworkException $e) {
            throw new ProviderNetworkException($this->name . ': ' . $e->getMessage(), $this->name, null, 'network', $e);
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw $this->mapError($response);
        }

        $data = $response->json();
        if ($data === null) {
            throw new ProviderUnavailableException(sprintf('%s returned a response that is not valid JSON.', $this->name), $this->name, $response->status, 'malformed_response');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    protected function mapError(HttpResponse $response): ProviderException
    {
        $status = $response->status;
        [$message, $type] = $this->extractError($response);
        $text = sprintf('%s returned HTTP %d%s: %s', $this->name, $status, $type !== null ? ' (' . $type . ')' : '', $message);
        $retryAfter = self::parseRetryAfter($response->header('retry-after'));

        if ($status === 429) {
            return (new ProviderRateLimitException($text, $this->name, $status, $type))->withRetryAfter($retryAfter);
        }
        if ($status === 408) {
            return new ProviderTimeoutException($text, $this->name, $status, $type);
        }
        if ($status >= 500) {
            return (new ProviderUnavailableException($text, $this->name, $status, $type))->withRetryAfter($retryAfter);
        }
        if ($status === 401 || $status === 403) {
            return new AuthenticationException($text, $this->name, $status, $type);
        }
        if ($status >= 400) {
            return new InvalidRequestException($text, $this->name, $status, $type);
        }

        return new ProviderException($text, $this->name, $status, $type);
    }

    /**
     * Understands the error shapes of Anthropic ({type, error: {type, message}}),
     * OpenAI-compatible APIs ({error: {message, type, code}}) and Google
     * ({error: {code, message, status}}).
     *
     * @return array{0: string, 1: ?string}
     */
    protected function extractError(HttpResponse $response): array
    {
        $data = $response->json();
        $error = $data['error'] ?? null;
        if (is_string($error)) {
            return [$error, null];
        }
        if (is_array($error)) {
            $message = isset($error['message']) && is_scalar($error['message']) ? (string) $error['message'] : 'unknown error';
            $type = null;
            foreach (['type', 'status', 'code'] as $key) {
                if (isset($error[$key]) && is_scalar($error[$key])) {
                    $type = (string) $error[$key];
                    break;
                }
            }

            return [$message, $type];
        }
        $body = trim(strip_tags($response->body));

        return [$body === '' ? 'empty response' : Str::limit($body, 200), null];
    }

    /**
     * Parses a Retry-After header: delta-seconds or an HTTP date.
     */
    public static function parseRetryAfter(?string $value, ?int $now = null): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return (float) max(0, $timestamp - ($now ?? time()));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function int(array $data, string ...$path): int
    {
        $value = $data;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return 0;
            }
            $value = $value[$key];
        }

        return is_numeric($value) ? (int) $value : 0;
    }
}
