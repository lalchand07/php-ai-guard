<?php

declare(strict_types=1);

namespace PhpAiGuard\Http;

use PhpAiGuard\Exception\ProviderNetworkException;
use PhpAiGuard\Exception\ProviderTimeoutException;

/**
 * Default transport built on ext-curl; works on any shared host.
 */
final class CurlHttpClient implements HttpClientInterface
{
    /**
     * @param array<int, mixed> $curlOptions extra CURLOPT_* options (e.g. a proxy or CA bundle)
     */
    public function __construct(private array $curlOptions = [])
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new ProviderNetworkException('Could not initialise cURL.');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) round($request->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($request->connectTimeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($request->body !== '') {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($handle, $this->curlOptions + $options);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle); // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new ProviderTimeoutException(sprintf('Request timed out after %.1f s (%s).', $request->timeout, self::host($request->url)));
        }
        if ($errno !== 0 || !is_string($body)) {
            throw new ProviderNetworkException(sprintf('Could not reach %s: %s', self::host($request->url), $error !== '' ? $error : 'unknown cURL error'));
        }

        return new HttpResponse($status, $body, $responseHeaders);
    }

    private static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?? 'provider');
    }
}
