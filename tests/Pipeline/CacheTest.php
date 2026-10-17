<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Pipeline;

use PhpAiGuard\Cache\ArrayCache;
use PhpAiGuard\Tests\Storage\TempDir;
use PhpAiGuard\Tests\TestCase;

final class CacheTest extends TestCase
{
    public function testIdenticalRequestsArePaidForOnce(): void
    {
        $ai = $this->guard(['cache' => ['store' => new ArrayCache($this->clock), 'ttl' => 600]]);
        $this->http->queue(self::anthropicText('cached answer', 100, 50));

        $first = $ai->forUser('a')->chat('What is PHP?');
        $second = $ai->forUser('b')->chat('What is PHP?');

        self::assertFalse($first->cacheHit);
        self::assertTrue($second->cacheHit);
        self::assertSame('cached answer', $second->text);
        self::assertSame(0.0, $second->cost);
        self::assertSame(100, $second->usage->inputTokens);
        self::assertCount(1, $this->http->requests);

        $records = $this->storage->records();
        self::assertFalse($records[0]->cacheHit);
        self::assertTrue($records[1]->cacheHit);
        self::assertSame(0, $records[1]->costMicros);
        self::assertSame('b', $records[1]->user);
    }

    public function testKeyCoversModelAndParameters(): void
    {
        $ai = $this->guard(['cache' => ['store' => new ArrayCache($this->clock)]]);
        $this->http->queue(self::anthropicText('a'))->queue(self::anthropicText('b'))->queue(self::anthropicText('c'))->queue(self::openAiText('d'));

        $ai->chat('Same prompt');
        $ai->chat('Same prompt', ['temperature' => 0.5]);
        $ai->chat('Same prompt', ['system' => 'Different system']);
        $ai->chat('Same prompt', ['tier' => 'cheap']);
        $ai->chat('Same prompt');

        self::assertCount(4, $this->http->requests);
    }

    public function testTtlExpiryAndOptOut(): void
    {
        $ai = $this->guard(['cache' => ['store' => new ArrayCache($this->clock), 'ttl' => 60]]);
        $this->http->queue(self::anthropicText('1'))->queue(self::anthropicText('2'))->queue(self::anthropicText('3'));

        $ai->chat('Hi');
        $this->clock->advance(61);
        self::assertSame('2', $ai->chat('Hi')->text, 'expired entries are fetched again');
        self::assertSame('3', $ai->chat('Hi', ['cache' => false])->text, 'per-request opt-out');
    }

    public function testFileCacheAndDefaultOff(): void
    {
        $dir = TempDir::create();
        $ai = $this->guard(['cache' => ['driver' => 'file', 'path' => $dir . '/cache', 'default' => false]]);
        $this->http->queue(self::anthropicText('x'))->queue(self::anthropicText('y'));

        $ai->chat('Hi');
        self::assertSame('y', $ai->chat('Hi')->text, 'caching is opt-in per request when default is false');

        $this->http->queue(self::anthropicText('z'));
        $ai->chat('Cache me', ['cache' => true]);
        self::assertTrue($ai->chat('Cache me', ['cache' => true])->cacheHit);
        self::assertCount(1, glob($dir . '/cache/*.json') ?: []);
        self::assertFileExists($dir . '/cache/.htaccess');
    }
}
