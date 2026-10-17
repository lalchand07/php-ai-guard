<?php

declare(strict_types=1);

namespace PhpAiGuard\Http;

use PhpAiGuard\Exception\ProviderNetworkException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adapter for any PSR-18 client (Guzzle, Symfony HttpClient, ...).
 *
 * Timeouts must be configured on the PSR-18 client itself: PSR-18 has no
 * per-request timeout option.
 */
final class Psr18HttpClient implements HttpClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== '') {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $e) {
            $host = (string) (parse_url($request->url, PHP_URL_HOST) ?? 'provider');
            throw new ProviderNetworkException(sprintf('Could not reach %s: %s', $host, $e->getMessage()), '', null, null, $e);
        }

        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return new HttpResponse($psrResponse->getStatusCode(), (string) $psrResponse->getBody(), $headers);
    }
}
