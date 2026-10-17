<?php

/**
 * php-ai-guard usage dashboard — one self-contained file.
 *
 * It has NO authentication of its own. Include it from a page that is already
 * protected by your application's login / admin check:
 *
 *     <?php
 *     require __DIR__ . '/vendor/autoload.php';
 *     require_admin();                                   // your own auth check
 *     $aiGuard = PhpAiGuard\AiGuard::create(require __DIR__ . '/ai-guard.php');
 *     require __DIR__ . '/vendor/your-vendor/php-ai-guard/dashboard/dashboard.php';
 *
 * Instead of $aiGuard you may set $aiGuardStorage (a StorageInterface) and,
 * optionally, $aiGuardTimezone. Query parameters: ?month=YYYY-MM&by=model.
 */

declare(strict_types=1);

use PhpAiGuard\AiGuard;
use PhpAiGuard\Report\Reporter;
use PhpAiGuard\Storage\StorageInterface;

(static function (?AiGuard $guard, ?StorageInterface $storage, string $timezone): void {
    if ($guard !== null) {
        $reporter = $guard->reporter();
    } elseif ($storage !== null) {
        $reporter = new Reporter($storage, $timezone);
    } else {
        http_response_code(500);
        echo 'php-ai-guard dashboard: set $aiGuard or $aiGuardStorage before including dashboard.php.';

        return;
    }

    $groups = ['model' => 'Model', 'tenant' => 'Tenant', 'user' => 'User', 'feature' => 'Feature', 'provider' => 'Provider', 'day' => 'Day', 'status' => 'Status'];
    $by = isset($_GET['by']) && is_string($_GET['by']) && isset($groups[$_GET['by']]) ? $_GET['by'] : 'model';
    $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $month = isset($_GET['month']) && is_string($_GET['month']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month']) === 1 ? $_GET['month'] : $now->format('Y-m');

    $rows = $reporter->month($month, $by);
    $totals = Reporter::totals($rows);
    $maxCost = 0.0;
    foreach ($rows as $row) {
        $maxCost = max($maxCost, $row['cost_usd']);
    }
    $previous = (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
    $next = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m');

    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $money = static function (float $usd): string {
        $decimals = $usd > 0 && $usd < 0.01 ? 6 : ($usd > 0 && $usd < 1 ? 4 : 2);

        return '$' . number_format($usd, $decimals);
    };
    $link = static fn (string $m, string $b): string => '?' . http_build_query(['month' => $m, 'by' => $b]);

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    } ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>AI usage · <?= $e($month) ?></title>
<style>
:root { --bg: #f7f7f8; --card: #fff; --text: #1d1d1f; --muted: #6b6b76; --line: #e4e4e8; --accent: #3b5bdb; --bar: #c5d0fa; --bad: #c92a2a; }
@media (prefers-color-scheme: dark) { :root { --bg: #141417; --card: #1d1d22; --text: #ececf1; --muted: #9a9aa6; --line: #2c2c33; --accent: #91a7ff; --bar: #34407a; --bad: #ff8787; } }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
h1 { font-size: 22px; margin: 0 0 4px; }
.sub { color: var(--muted); margin: 0 0 20px; }
nav { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 20px; }
nav a { color: var(--accent); text-decoration: none; padding: 4px 10px; border: 1px solid var(--line); border-radius: 999px; background: var(--card); }
nav a.on { background: var(--accent); color: var(--card); border-color: var(--accent); }
nav .month { font-weight: 600; padding: 0 6px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; }
.card b { display: block; font-size: 22px; font-variant-numeric: tabular-nums; }
.card span { color: var(--muted); font-size: 13px; }
.table { background: var(--card); border: 1px solid var(--line); border-radius: 10px; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
th, td { padding: 9px 12px; border-bottom: 1px solid var(--line); text-align: right; white-space: nowrap; }
th { color: var(--muted); font-weight: 500; font-size: 13px; }
th:first-child, td:first-child { text-align: left; max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
tr:last-child td { border-bottom: 0; }
td.cost { position: relative; min-width: 140px; }
td.cost i { position: absolute; inset: 6px auto 6px 0; background: var(--bar); border-radius: 3px; z-index: 0; }
td.cost em { position: relative; z-index: 1; font-style: normal; }
.err { color: var(--bad); }
.empty { padding: 32px; text-align: center; color: var(--muted); }
footer { color: var(--muted); font-size: 12px; margin-top: 16px; }
</style>
</head>
<body>
<main>
  <h1>AI usage</h1>
  <p class="sub">Costs are calculated from your configured pricing table and are estimates, not invoices.</p>

  <nav aria-label="Month">
    <a href="<?= $e($link($previous, $by)) ?>">&larr; <?= $e($previous) ?></a>
    <span class="month"><?= $e($month) ?></span>
    <a href="<?= $e($link($next, $by)) ?>"><?= $e($next) ?> &rarr;</a>
  </nav>
  <nav aria-label="Group by">
    <?php foreach ($groups as $key => $label): ?>
      <a class="<?= $key === $by ? 'on' : '' ?>" href="<?= $e($link($month, $key)) ?>"><?= $e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="cards">
    <div class="card"><b><?= $e($money($totals['cost_usd'])) ?></b><span>Total cost</span></div>
    <div class="card"><b><?= $e(number_format($totals['requests'])) ?></b><span>Requests</span></div>
    <div class="card"><b><?= $e(number_format($totals['input_tokens'] + $totals['output_tokens'])) ?></b><span>Tokens (in + out)</span></div>
    <div class="card"><b><?= $e(number_format($totals['cache_hits'])) ?></b><span>Cache hits</span></div>
    <div class="card"><b class="<?= $totals['errors'] > 0 ? 'err' : '' ?>"><?= $e(number_format($totals['errors'])) ?></b><span>Errors / refusals</span></div>
  </section>

  <div class="table">
  <?php if ($rows === []): ?>
    <div class="empty">No usage recorded for <?= $e($month) ?>.</div>
  <?php else: ?>
    <table>
      <thead><tr><th><?= $e($groups[$by]) ?></th><th>Requests</th><th>Input tokens</th><th>Output tokens</th><th>Cached</th><th>Cache hits</th><th>Errors</th><th>Avg ms</th><th>Cost</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td title="<?= $e($row['group']) ?>"><?= $e($row['group']) ?></td>
          <td><?= $e(number_format($row['requests'])) ?></td>
          <td><?= $e(number_format($row['input_tokens'])) ?></td>
          <td><?= $e(number_format($row['output_tokens'])) ?></td>
          <td><?= $e(number_format($row['cached_tokens'])) ?></td>
          <td><?= $e(number_format($row['cache_hits'])) ?></td>
          <td class="<?= $row['errors'] > 0 ? 'err' : '' ?>"><?= $e(number_format($row['errors'])) ?></td>
          <td><?= $e(number_format($row['avg_latency_ms'])) ?></td>
          <td class="cost"><i style="width: <?= $maxCost > 0 ? round($row['cost_usd'] / $maxCost * 100, 1) : 0 ?>%"></i><em><?= $e($money($row['cost_usd'])) ?></em></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
  <footer>Times grouped in <?= $e($timezone) ?> (days in UTC). Generated <?= $e($now->format('Y-m-d H:i')) ?> by php-ai-guard.</footer>
</main>
</body>
</html>
<?php
})(
    isset($aiGuard) && $aiGuard instanceof AiGuard ? $aiGuard : null,
    isset($aiGuardStorage) && $aiGuardStorage instanceof StorageInterface ? $aiGuardStorage : null,
    isset($aiGuardTimezone) && is_string($aiGuardTimezone) ? $aiGuardTimezone : 'UTC'
);
