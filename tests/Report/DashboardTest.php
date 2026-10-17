<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Report;

use PhpAiGuard\Storage\InMemoryStorage;
use PhpAiGuard\Storage\UsageRecord;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    /**
     * @param array<string, string> $query
     */
    private function render(InMemoryStorage $storage, array $query): string
    {
        $_GET = $query;
        $aiGuardStorage = $storage;
        ob_start();
        require __DIR__ . '/../../dashboard/dashboard.php';

        return (string) ob_get_clean();
    }

    public function testRendersReportAndEscapesUserControlledValues(): void
    {
        $storage = new InMemoryStorage();
        $t = (int) strtotime('2026-10-05 10:00:00 UTC');
        $storage->logUsage(new UsageRecord($t, 'anthropic', 'claude-haiku-5-5', 'ok', '<script>alert(1)</script>', 'u1', null, 1000, 200, 0, 200));
        $storage->logUsage(new UsageRecord($t, 'groq', 'openai/gpt-oss-20b', 'error', 'acme', 'u2', null, 0, 0, 0, 0, 0, false, 'ProviderUnavailableException:503'));

        $html = $this->render($storage, ['month' => '2026-10', 'by' => 'tenant']);

        self::assertStringContainsString('<title>AI usage · 2026-10</title>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('$0.000200', $html);
        self::assertStringContainsString('class="on" href="?month=2026-10&amp;by=tenant"', $html);
    }

    public function testIgnoresInvalidQueryParameters(): void
    {
        $html = $this->render(new InMemoryStorage(), ['month' => '"><img src=x>', 'by' => 'password']);

        self::assertStringNotContainsString('<img src=x>', $html);
        self::assertStringContainsString('No usage recorded', $html);
        self::assertStringContainsString('class="on" href="?month=', $html);
    }
}
