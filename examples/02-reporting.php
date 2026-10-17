<?php

/*
 * Reading the usage log from code (the CLI and dashboard use the same API).
 *
 *   php examples/02-reporting.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PhpAiGuard\Report\TableRenderer;

$ai = example_guard();
$month = (new DateTimeImmutable())->format('Y-m');

foreach (['user', 'feature', 'model'] as $by) {
    $rows = $ai->reporter()->month($month, $by);
    echo "\nUsage for $month by $by\n";
    echo $rows === [] ? "No usage yet: run 01-basic.php first.\n" : TableRenderer::text($rows, ucfirst($by));
}
