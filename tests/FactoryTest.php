<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests;

use PhpAiGuard\AiGuard;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Message;
use PhpAiGuard\Provider\AbstractHttpProvider;
use PhpAiGuard\Request;
use PhpAiGuard\Storage\FileStorage;
use PhpAiGuard\Storage\PdoStorage;
use PhpAiGuard\Tests\Storage\TempDir;

final class FactoryTest extends TestCase
{
    /**
     * @dataProvider invalidConfigs
     *
     * @param array<string, mixed> $overrides
     */
    public function testInvalidConfigFailsLoudly(array $overrides, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->guard($overrides);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'typo in top-level key' => [['budget' => []], 'Unknown config key(s): budget'];
        yield 'typo in provider setting' => [['providers' => ['anthropic' => ['apikey' => 'x']]], 'unknown setting(s) apikey'];
        yield 'unknown provider type' => [['providers' => ['x' => ['type' => 'mistral', 'api_key' => 'k']], 'tiers' => []], 'unknown type "mistral"'];
        yield 'tier uses unconfigured provider' => [['tiers' => ['standard' => ['groq:llama']]], 'provider "groq", which is not configured'];
        yield 'no providers' => [['providers' => []], 'at least one provider'];
        yield 'bad window' => [['budgets' => ['user' => ['yearly' => 1]]], 'unknown window "yearly"'];
        yield 'bad rate limit' => [['rate_limits' => ['user' => 0]], 'positive number'];
        yield 'bad unknown_pricing' => [['unknown_pricing' => 'maybe'], '"unknown_pricing" must be'];
        yield 'bad storage driver' => [['storage' => ['driver' => 'redis']], 'Unknown storage driver "redis"'];
        yield 'bad privacy key' => [['privacy' => ['redcat' => true]], 'Unknown "privacy" key(s): redcat'];
        yield 'bad json mode' => [['providers' => ['anthropic' => ['api_key' => 'k', 'json_mode' => 'strict']], 'tiers' => ['standard' => 'anthropic:x']], 'json_mode must be one of'];
        yield 'bad timezone' => [['timezone' => 'Mars/Olympus'], 'Invalid timezone'];
    }

    public function testStorageDrivers(): void
    {
        $dir = TempDir::create();
        $ai = $this->guard(['storage' => ['driver' => 'sqlite', 'path' => $dir . '/db/ai.sqlite'], 'budgets' => ['global' => ['monthly' => 1]]]);
        self::assertInstanceOf(PdoStorage::class, $ai->storage());
        self::assertFileExists($dir . '/db/ai.sqlite');

        $ai = $this->guard(['storage' => ['driver' => 'file', 'path' => $dir . '/files']]);
        self::assertInstanceOf(FileStorage::class, $ai->storage());

        $ai = $this->guard(['storage' => new \PDO('sqlite::memory:')]);
        self::assertInstanceOf(PdoStorage::class, $ai->storage());
    }

    public function testDefaultsAndRemainingBudget(): void
    {
        $this->http->queue(self::anthropicText('ok'));
        $ai = $this->guard(['defaults' => ['max_tokens' => 77, 'temperature' => 0.1], 'budgets' => ['tenant' => ['daily' => 3.0], 'global' => ['monthly' => 9.0]]]);

        $ai->chat('hi');
        $body = $this->http->lastRequestJson();
        self::assertSame(77, $body['max_tokens']);
        self::assertSame(0.1, $body['temperature']);

        self::assertSame(3.0, $ai->forTenant('acme')->remainingBudget('tenant', null, 'daily'));
        self::assertNull($ai->forUser('u')->remainingBudget('user'));
        self::assertLessThan(9.0, (float) $ai->remainingBudget('global'));
    }

    public function testSendWithConversationAndContext(): void
    {
        $this->http->queue(self::anthropicText('Paris'));
        $ai = $this->guard()->forUser('9', 'acme');

        $response = $ai->send(new Request([Message::user('Capital of France?')], 'Answer in one word.'));
        self::assertSame('Paris', (string) $response);
        self::assertSame('9', $this->storage->records()[0]->user);

        $this->http->queue(self::anthropicText('Berlin'));
        $ai->chat([['role' => 'user', 'content' => 'Capital of France?'], ['role' => 'assistant', 'content' => 'Paris'], ['role' => 'user', 'content' => 'And Germany?']]);
        self::assertCount(3, $this->http->lastRequestJson()['messages']);
    }

    public function testUnknownChatOptionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown option(s): maxTokens');
        $this->guard()->chat('hi', ['maxTokens' => 5]);
    }

    public function testRetryAfterParsing(): void
    {
        self::assertSame(12.0, AbstractHttpProvider::parseRetryAfter('12'));
        self::assertSame(1.5, AbstractHttpProvider::parseRetryAfter('1.5'));
        self::assertSame(30.0, AbstractHttpProvider::parseRetryAfter('Sat, 17 Oct 2026 12:00:30 GMT', (int) strtotime('2026-10-17 12:00:00 UTC')));
        self::assertNull(AbstractHttpProvider::parseRetryAfter('soon'));
        self::assertNull(AbstractHttpProvider::parseRetryAfter(null));
    }

    public function testCreateIsTheDocumentedEntryPoint(): void
    {
        self::assertInstanceOf(AiGuard::class, $this->guard());
    }
}
