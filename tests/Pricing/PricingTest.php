<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Pricing;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Pricing\ModelPrice;
use PhpAiGuard\Pricing\PricingTable;
use PhpAiGuard\Usage;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    public function testCostWithPromptCache(): void
    {
        // Prices per 1M: input 4, output 20, cache read 0.20, cache write 5.
        $price = new ModelPrice(4.0, 20.0, 0.20, 5.0);
        $usage = new Usage(6197, 503, 2051, 2051); // 2095 uncached + 2051 read + 2051 written

        $expected = (2095 * 4.0 + 2051 * 0.20 + 2051 * 5.0 + 503 * 20.0) / 1_000_000;
        self::assertEqualsWithDelta($expected, $price->cost($usage), 1e-12);
    }

    public function testMaxCostIsWorstCase(): void
    {
        $price = new ModelPrice(1.0, 5.0, 0.1, 1.25);
        self::assertEqualsWithDelta((1000 * 1.0 + 500 * 5.0) / 1e6, $price->maxCost(1000, 500), 1e-12);
        self::assertEqualsWithDelta((1000 * 1.25 + 500 * 5.0) / 1e6, $price->maxCost(1000, 500, true), 1e-12);
    }

    public function testLongContextTier(): void
    {
        $price = ModelPrice::fromArray(['input' => 0.10, 'output' => 0.50, 'long_context' => ['threshold' => 100000, 'input' => 0.50, 'output' => 2.50]]);
        self::assertEqualsWithDelta((100000 * 0.10 + 1000 * 0.50) / 1e6, $price->cost(new Usage(100000, 1000)), 1e-12);
        self::assertEqualsWithDelta((100001 * 0.50 + 1000 * 2.50) / 1e6, $price->cost(new Usage(100001, 1000)), 1e-12);
        self::assertEqualsWithDelta((200000 * 0.50 + 10 * 2.50) / 1e6, $price->maxCost(200000, 10), 1e-12);
    }

    public function testConservativeDefaultsForCachePrices(): void
    {
        $price = ModelPrice::fromArray(['input' => 2, 'output' => 8]);
        self::assertSame(2.0, $price->cacheRead);
        self::assertSame(4.0, $price->cacheWrite);
    }

    public function testTableLookup(): void
    {
        $table = new PricingTable([
            'anthropic' => ['claude-haiku-5-5' => ['input' => 0.1, 'output' => 0.5]],
            'gemini' => ['gemini-3.8-flash' => ['input' => 0.75, 'output' => 3.75]],
            'ollama' => ['*' => ['free' => true]],
        ]);
        self::assertSame(0.1, $table->find('anthropic', 'claude-haiku-5-5')?->input);
        self::assertNull($table->find('anthropic', 'claude-other'));
        self::assertSame(0.75, $table->find('gemini', 'models/gemini-3.8-flash')?->input);
        self::assertTrue($table->find('ollama', 'llama3.2:3b')?->isFree());
        self::assertNull($table->find('groq', 'x'));
    }

    public function testExamplePricingFileIsValid(): void
    {
        $pricing = require __DIR__ . '/../../config/pricing.example.php';
        self::assertIsArray($pricing);
        $table = new PricingTable($pricing);
        self::assertNotNull($table->find('anthropic', 'claude-haiku-5-5'));
    }

    public function testRejectsIncompletePrices(): void
    {
        $this->expectException(ConfigurationException::class);
        ModelPrice::fromArray(['input' => 1]);
    }
}
