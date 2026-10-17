<?php

declare(strict_types=1);

namespace PhpAiGuard\Pricing;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Usage;

/**
 * Prices in USD per one million tokens.
 *
 * Unset cache prices fall back to conservative values: cache reads cost the
 * normal input price and cache writes cost twice the input price.
 */
final class ModelPrice
{
    public float $cacheRead;
    public float $cacheWrite;

    public function __construct(
        public float $input,
        public float $output,
        ?float $cacheRead = null,
        ?float $cacheWrite = null,
        public ?int $longContextThreshold = null,
        public ?ModelPrice $longContext = null
    ) {
        if ($input < 0 || $output < 0 || ($cacheRead ?? 0) < 0 || ($cacheWrite ?? 0) < 0) {
            throw new ConfigurationException('Prices cannot be negative.');
        }
        $this->cacheRead = $cacheRead ?? $input;
        $this->cacheWrite = $cacheWrite ?? $input * 2;
    }

    public static function free(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0);
    }

    /**
     * @param array<string, mixed> $config ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3, 'cache_write' => 3.75,
     *                                     'long_context' => ['threshold' => 200000, 'input' => ..., 'output' => ...]]
     */
    public static function fromArray(array $config, string $label = 'model'): self
    {
        if (($config['free'] ?? false) === true) {
            return self::free();
        }
        foreach (['input', 'output'] as $key) {
            if (!isset($config[$key]) || !is_numeric($config[$key])) {
                throw new ConfigurationException(sprintf('Pricing for %s needs numeric "input" and "output" (USD per 1M tokens), or "free" => true.', $label));
            }
        }
        $long = null;
        $threshold = null;
        if (isset($config['long_context']) && is_array($config['long_context'])) {
            $threshold = (int) ($config['long_context']['threshold'] ?? 0);
            if ($threshold <= 0) {
                throw new ConfigurationException(sprintf('Pricing for %s: long_context needs a positive "threshold" (input tokens).', $label));
            }
            $long = self::fromArray($config['long_context'], $label . ' (long context)');
        }

        return new self(
            (float) $config['input'],
            (float) $config['output'],
            isset($config['cache_read']) ? (float) $config['cache_read'] : null,
            isset($config['cache_write']) ? (float) $config['cache_write'] : null,
            $threshold,
            $long
        );
    }

    public function isFree(): bool
    {
        return $this->input == 0.0 && $this->output == 0.0 && $this->cacheRead == 0.0 && $this->cacheWrite == 0.0 && ($this->longContext === null || $this->longContext->isFree());
    }

    /** Picks the long-context price row when the prompt is above its threshold. */
    public function forInputTokens(int $inputTokens): self
    {
        if ($this->longContext !== null && $this->longContextThreshold !== null && $inputTokens > $this->longContextThreshold) {
            return $this->longContext;
        }

        return $this;
    }

    /** Actual cost in USD of a completed call. */
    public function cost(Usage $usage): float
    {
        $price = $this->forInputTokens($usage->inputTokens);
        $cached = min($usage->inputTokens, $usage->cacheReadTokens + $usage->cacheWriteTokens);
        $uncached = $usage->inputTokens - $cached;
        $read = min($usage->cacheReadTokens, $cached);
        $write = $cached - $read;

        return ($uncached * $price->input
            + $read * $price->cacheRead
            + $write * $price->cacheWrite
            + $usage->outputTokens * $price->output) / 1_000_000;
    }

    /**
     * Worst-case cost in USD before a call: every input token at the highest
     * applicable input price and the full max_tokens of output.
     */
    public function maxCost(int $inputTokens, int $maxOutputTokens, bool $promptCaching = false): float
    {
        $price = $this->forInputTokens($inputTokens);
        $inputPrice = $promptCaching ? max($price->input, $price->cacheWrite) : $price->input;

        return ($inputTokens * $inputPrice + $maxOutputTokens * $price->output) / 1_000_000;
    }
}
