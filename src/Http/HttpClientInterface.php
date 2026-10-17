<?php

declare(strict_types=1);

namespace PhpAiGuard\Http;

use PhpAiGuard\Exception\ProviderNetworkException;
use PhpAiGuard\Exception\ProviderTimeoutException;

/**
 * Sends one HTTP request. Must return any HTTP status (including 4xx/5xx) as a
 * response and only throw for transport failures.
 */
interface HttpClientInterface
{
    /**
     * @throws ProviderTimeoutException when the request timed out
     * @throws ProviderNetworkException for any other transport failure
     */
    public function send(HttpRequest $request): HttpResponse;
}
