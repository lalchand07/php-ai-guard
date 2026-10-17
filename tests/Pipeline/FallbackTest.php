<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Pipeline;

use PhpAiGuard\Attempt;
use PhpAiGuard\Exception\AllProvidersFailedException;
use PhpAiGuard\Exception\AuthenticationException;
use PhpAiGuard\Exception\ContentBlockedException;
use PhpAiGuard\Exception\InvalidRequestException;
use PhpAiGuard\Exception\ProviderNetworkException;
use PhpAiGuard\Exception\ProviderTimeoutException;
use PhpAiGuard\Http\HttpResponse;
use PhpAiGuard\Tests\TestCase;

final class FallbackTest extends TestCase
{
    public function testFallsBackInOrderAfterRetries(): void
    {
        // anthropic: 529 three times (1 try + 2 retries) → gemini: 503 three times → openai: success
        $this->http
            ->queue(self::error(529, 'overloaded_error', 'Overloaded'))
            ->queue(self::error(529, 'overloaded_error', 'Overloaded'))
            ->queue(self::error(529, 'overloaded_error', 'Overloaded'))
            ->queue(self::error(503))
            ->queue(self::error(503))
            ->queue(self::error(503))
            ->queue(self::openAiText('from openai'));

        $response = $this->guard()->chat('Hello');

        self::assertSame('from openai', $response->text);
        self::assertSame('openai', $response->provider);
        self::assertSame('gpt-test', $response->model);
        self::assertSame([
            'api.anthropic.com', 'api.anthropic.com', 'api.anthropic.com',
            'generativelanguage.googleapis.com', 'generativelanguage.googleapis.com', 'generativelanguage.googleapis.com',
            'api.openai.com',
        ], $this->requestedHosts());
        self::assertCount(4, $this->sleeper->sleeps, 'two backoffs per failing target');
        self::assertSame(
            [Attempt::ERROR, Attempt::ERROR, Attempt::ERROR, Attempt::ERROR, Attempt::ERROR, Attempt::ERROR, Attempt::OK],
            array_map(static fn (Attempt $a): string => $a->status, $response->attempts)
        );
    }

    public function testExponentialBackoffWithJitterIsBounded(): void
    {
        $this->http->queue(self::error(500))->queue(self::error(500))->queue(self::error(500))->queue(self::error(500))
            ->queue(self::anthropicText('ok'));

        $this->guard(['retry' => ['max_retries' => 4, 'base_delay_ms' => 100, 'max_delay_ms' => 300]])->chat('Hello', ['model' => 'anthropic:claude-haiku-5-5']);

        self::assertCount(4, $this->sleeper->sleeps);
        foreach ([0.1, 0.2, 0.3, 0.3] as $i => $cap) {
            self::assertGreaterThanOrEqual(0.0, $this->sleeper->sleeps[$i]);
            self::assertLessThanOrEqual($cap, $this->sleeper->sleeps[$i], "retry $i is capped at {$cap}s");
        }
    }

    public function testHonoursRetryAfter(): void
    {
        $this->http->queue(self::error(429, 'rate_limit_error', 'slow down', ['retry-after' => '3']))->queue(self::anthropicText('ok'));

        $response = $this->guard()->chat('Hello');

        self::assertSame('ok', $response->text);
        self::assertSame([3.0], $this->sleeper->sleeps);
    }

    public function testRetryAfterLongerThanAllowedFallsBackImmediately(): void
    {
        $this->http->queue(self::error(429, 'rate_limit_error', 'quota', ['retry-after' => '3600']))->queue(self::geminiText('from gemini'));

        $response = $this->guard()->chat('Hello');

        self::assertSame('gemini', $response->provider);
        self::assertSame([], $this->sleeper->sleeps, 'never sleeps an hour inside a web request');
    }

    public function testTimeoutsAndNetworkErrorsFallBack(): void
    {
        $this->http->queue(new ProviderTimeoutException('timed out'))
            ->queue(new ProviderTimeoutException('timed out'))
            ->queue(new ProviderTimeoutException('timed out'))
            ->queue(new ProviderNetworkException('connection reset'))
            ->queue(new ProviderNetworkException('connection reset'))
            ->queue(new ProviderNetworkException('connection reset'))
            ->queue(self::openAiText('third time lucky'));

        self::assertSame('third time lucky', $this->guard()->chat('Hello')->text);
    }

    public function testNeverFallsBackOnValidationErrors(): void
    {
        $this->http->queue(self::fixtureResponse('anthropic/error_invalid_request', 400))->queue(self::geminiText('should not be used'));

        try {
            $this->guard()->chat('Hello');
            self::fail('Expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertSame(400, $e->getStatusCode());
        }
        self::assertSame(['api.anthropic.com'], $this->requestedHosts());
        self::assertSame(1, $this->http->remaining());
        self::assertSame([], $this->sleeper->sleeps);
    }

    public function testNeverFallsBackOnAuthOrSafetyErrors(): void
    {
        $this->http->queue(self::error(401, 'authentication_error', 'bad key'));
        try {
            $this->guard()->chat('Hello');
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException) {
        }

        $this->http->queue(self::fixtureResponse('gemini/prompt_blocked'));
        $this->expectException(ContentBlockedException::class);
        $this->guard()->chat('Hello', ['model' => 'gemini:gemini-3.8-flash']);
    }

    public function testAllProvidersFailed(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->http->queue(self::error(503))->queue(self::error(503))->queue(self::error(503));
        }

        try {
            $this->guard(['budgets' => ['global' => ['monthly' => 5.0]]])->chat('Hello');
            self::fail('Expected AllProvidersFailedException');
        } catch (AllProvidersFailedException $e) {
            self::assertCount(9, $e->getAttempts());
            self::assertStringContainsString('anthropic:claude-haiku-5-5', $e->getMessage());
            self::assertStringContainsString('openai:gpt-test', $e->getMessage());
            self::assertInstanceOf(\PhpAiGuard\Exception\ProviderUnavailableException::class, $e->getPrevious());
        }
        // Failed calls cost nothing: every reservation was released.
        self::assertSame(5.0, $this->guard(['budgets' => ['global' => ['monthly' => 5.0]]])->remainingBudget('global'));
        self::assertSame(9, count(array_filter($this->storage->records(), static fn ($r): bool => $r->status === 'error')));
    }

    public function testMalformedSuccessBodyIsRetryable(): void
    {
        $this->http->queue(new HttpResponse(200, 'not json'))->queue(self::anthropicText('ok'));

        self::assertSame('ok', $this->guard()->chat('Hello')->text);
    }
}
