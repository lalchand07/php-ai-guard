<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Pipeline;

use PhpAiGuard\Tests\TestCase;

final class UsageLoggingTest extends TestCase
{
    public function testLogsOneRowPerCallWithContextAndCost(): void
    {
        $this->http->queue(self::anthropicText('Summary.', 2000, 100));

        $response = $this->guard()->forUser('42', 'acme')->withFeature('summary')->chat('Summarise: secret plans');

        $records = $this->storage->records();
        self::assertCount(1, $records);
        $r = $records[0];
        self::assertSame(['acme', '42', 'summary'], [$r->tenant, $r->user, $r->feature]);
        self::assertSame(['anthropic', 'claude-haiku-5-5', 'ok'], [$r->provider, $r->model, $r->status]);
        self::assertSame([2000, 100], [$r->inputTokens, $r->outputTokens]);
        self::assertSame(2000 + 200, $r->costMicros); // 2000 × $1/1M + 100 × $2/1M
        self::assertEqualsWithDelta(0.0022, $response->cost, 1e-12);
        self::assertNull($r->promptText, 'prompts are not logged by default');
        self::assertNull($r->responseText);
    }

    public function testPromptLoggingIsOptInAndRedacted(): void
    {
        $this->http->queue(self::anthropicText('Done'));

        $this->guard(['log_prompts' => true, 'privacy' => ['redact' => true]])->chat('Email me at jo@example.com', ['system' => 'Be nice']);

        $r = $this->storage->records()[0];
        self::assertSame("Be nice\nEmail me at [EMAIL_1]", $r->promptText);
        self::assertSame('Done', $r->responseText);
    }

    public function testApiKeysNeverReachTheLog(): void
    {
        $this->http->queue(self::error(401, 'authentication_error', 'invalid x-api-key'));
        try {
            $this->guard(['log_prompts' => true])->chat('hi');
        } catch (\Throwable) {
        }

        $dump = serialize($this->storage->records());
        foreach (['sk-ant-test-secret', 'gemini-test-secret', 'sk-openai-test-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        self::assertSame('AuthenticationException:401', $this->storage->records()[0]->errorType);
    }

    public function testFeatureRoutingUsesMappedTier(): void
    {
        $this->http->queue(self::openAiText('positive'));

        $response = $this->guard(['features' => ['classify' => 'cheap']])->withFeature('classify')->chat('Great product!');

        self::assertSame('openai', $response->provider);
    }
}
