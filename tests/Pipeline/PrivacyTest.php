<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Pipeline;

use PhpAiGuard\Exception\PrivacyException;
use PhpAiGuard\Tests\TestCase;

final class PrivacyTest extends TestCase
{
    public function testRedactsForProvidersThatMayNotReceivePersonalDataAndRestores(): void
    {
        $ai = $this->guard([
            'providers' => [
                'anthropic' => ['api_key' => 'k', 'allows_personal_data' => true],
                'openai' => ['api_key' => 'k', 'allows_personal_data' => false],
            ],
            'tiers' => ['standard' => ['openai:gpt-test']],
        ]);
        $this->http->queue(self::openAiText('I will email [EMAIL_1] and call [PHONE_1].'));

        $response = $ai->chat('Contact jane.doe@example.com or +44 20 7946 0958 about the order.', ['system' => 'Card on file: 4111 1111 1111 1111']);

        $sent = $this->http->lastRequestJson();
        self::assertSame('Card on file: [CARD_1]', $sent['messages'][0]['content']);
        self::assertSame('Contact [EMAIL_1] or [PHONE_1] about the order.', $sent['messages'][1]['content']);
        self::assertSame('I will email jane.doe@example.com and call +44 20 7946 0958.', $response->text);
    }

    public function testAllowedProvidersReceiveTheOriginalText(): void
    {
        $this->http->queue(self::anthropicText('ok'));
        $this->guard()->chat('Mail jane@example.com');
        self::assertSame('Mail jane@example.com', $this->http->lastRequestJson()['messages'][0]['content']);
    }

    public function testRedactAllWithoutRestore(): void
    {
        $ai = $this->guard(['privacy' => ['redact' => true, 'restore' => false, 'custom' => ['EMPLOYEE_ID' => '/\bEMP-\d{6}\b/']]]);
        $this->http->queue(self::anthropicText('Employee [EMPLOYEE_ID_1] notified.'));

        $response = $ai->chat('Notify EMP-123456 at GB82 WEST 1234 5698 7654 32');

        self::assertSame('Notify [EMPLOYEE_ID_1] at [IBAN_1]', $this->http->lastRequestJson()['messages'][0]['content']);
        self::assertSame('Employee [EMPLOYEE_ID_1] notified.', $response->text);
    }

    public function testSkipPolicyFallsBackToAllowedProvider(): void
    {
        $ai = $this->guard([
            'providers' => [
                'gemini' => ['api_key' => 'k', 'allows_personal_data' => false],
                'anthropic' => ['api_key' => 'k'],
            ],
            'tiers' => ['standard' => ['gemini:gemini-3.8-flash', 'anthropic:claude-haiku-5-5']],
            'privacy' => ['on_disallowed' => 'skip'],
        ]);
        $this->http->queue(self::anthropicText('handled by the paid provider'))->queue(self::geminiText('no PII here'));

        self::assertSame('anthropic', $ai->chat('My email is a@b.co')->provider);
        self::assertSame('gemini', $ai->chat('What is 2+2?')->provider, 'requests without personal data still use the free tier');
    }

    public function testSkipPolicyWithNoAllowedProviderThrows(): void
    {
        $ai = $this->guard([
            'providers' => ['gemini' => ['api_key' => 'k', 'allows_personal_data' => false]],
            'tiers' => ['standard' => ['gemini:gemini-3.8-flash']],
            'privacy' => ['on_disallowed' => 'skip'],
        ]);

        $this->expectException(PrivacyException::class);
        $ai->chat('My card is 4111111111111111');
    }

    public function testJsonValuesAreRestored(): void
    {
        $ai = $this->guard(['privacy' => ['redact' => true]]);
        $this->http->queue(self::anthropicText('{"contact": "[EMAIL_1]"}'));

        $data = $ai->json('Extract the contact: bob@example.org', ['type' => 'object', 'properties' => ['contact' => ['type' => 'string', 'format' => 'email']]]);

        self::assertSame(['contact' => 'bob@example.org'], $data);
    }
}
