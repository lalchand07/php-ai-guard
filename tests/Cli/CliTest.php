<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Cli;

use PhpAiGuard\Cli\Application;
use PhpAiGuard\Storage\PdoStorage;
use PhpAiGuard\Storage\UsageRecord;
use PhpAiGuard\Tests\Storage\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension pdo_sqlite
 */
final class CliTest extends TestCase
{
    private string $config;

    protected function setUp(): void
    {
        $dir = TempDir::create();
        $db = $dir . '/usage.sqlite';
        $this->config = $dir . '/ai-guard.php';
        file_put_contents($this->config, '<?php return ' . var_export(['storage' => ['driver' => 'sqlite', 'path' => $db], 'timezone' => 'UTC'], true) . ';');

        $storage = PdoStorage::sqlite($db);
        $oct = (int) strtotime('2026-10-05 10:00:00 UTC');
        $storage->logUsage(new UsageRecord($oct, 'anthropic', 'claude-haiku-5-5', 'ok', 'acme', 'u1', 'summary', 1200, 300, 0, 270, 800));
        $storage->logUsage(new UsageRecord($oct + 60, 'anthropic', 'claude-haiku-5-5', 'ok', 'acme', 'u2', 'summary', 1000, 100, 1000, 0, 0, true));
        $storage->logUsage(new UsageRecord($oct + 120, 'groq', 'openai/gpt-oss-20b', 'ok', '=HYPERLINK("x")', 'u3', 'classify', 500, 20, 0, 44, 120));
        $storage->logUsage(new UsageRecord((int) strtotime('2026-09-30 10:00:00 UTC'), 'groq', 'openai/gpt-oss-20b', 'ok', 'acme', 'u1', null, 1, 1, 0, 999));
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function cli(array $args): array
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Application($out, $err))->run(array_merge(['ai-guard'], $args));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testReportTableByTenant(): void
    {
        [$code, $out] = $this->cli(['report', '--month=2026-10', '--by=tenant', '--config=' . $this->config]);

        self::assertSame(0, $code);
        self::assertStringContainsString('AI usage for 2026-10 by tenant', $out);
        self::assertMatchesRegularExpression('/\| acme\s+\|\s+2 \|\s+2,200 \|\s+400 \|\s+1,000 \|\s+1 \|\s+0 \|\s+400 \|\s+\$0\.000270 \|/', $out);
        self::assertStringContainsString('$0.000044', $out);
        self::assertStringContainsString('| TOTAL', $out);
        self::assertStringNotContainsString('0.0010', $out, 'September usage is excluded');
    }

    public function testCsvIsSafeForSpreadsheets(): void
    {
        [$code, $out] = $this->cli(['report', '--month=2026-10', '--by=tenant', '--csv', '--config=' . $this->config]);

        self::assertSame(0, $code);
        $lines = explode("\n", trim($out));
        self::assertSame('tenant,requests,input_tokens,output_tokens,cached_tokens,cache_hits,errors,avg_latency_ms,cost_usd', $lines[0]);
        self::assertSame('acme,2,2200,400,1000,1,0,400,0.000270', $lines[1]);
        self::assertStringStartsWith('"\'=HYPERLINK(""x"")"', $lines[2]);
    }

    public function testReportByModelAndFeature(): void
    {
        [, $out] = $this->cli(['report', '--month=2026-10', '--by=model', '--config=' . $this->config]);
        self::assertStringContainsString('anthropic:claude-haiku-5-5', $out);
        [, $out] = $this->cli(['report', '--month=2026-10', '--by=feature', '--config=' . $this->config]);
        self::assertStringContainsString('classify', $out);
    }

    public function testErrors(): void
    {
        [$code, , $err] = $this->cli(['report', '--month=October', '--config=' . $this->config]);
        self::assertSame(1, $code);
        self::assertStringContainsString('--month must look like', $err);

        [$code, , $err] = $this->cli(['report', '--by=colour', '--config=' . $this->config]);
        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot group usage by "colour"', $err);

        [$code, , $err] = $this->cli(['report', '--config=/nonexistent.php']);
        self::assertSame(1, $code);
        self::assertStringContainsString('No config found', $err);

        [$code, $out] = $this->cli(['help']);
        self::assertSame(0, $code);
        self::assertStringContainsString('ai-guard report', $out);
    }

    public function testInstallAndPrune(): void
    {
        [$code, $out] = $this->cli(['install', '--config=' . $this->config]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Tables created', $out);
        [$code, $out] = $this->cli(['prune', '--config=' . $this->config]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Removed 0', $out);
    }

    public function testBinaryRuns(): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/ai-guard') . ' report --month=2026-10 --by=user --config=' . escapeshellarg($this->config) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        self::assertStringContainsString('u1', implode("\n", $output));
    }
}
