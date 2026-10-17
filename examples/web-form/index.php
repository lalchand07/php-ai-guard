<?php

/*
 * A minimal web form: ask a question, get an answer, see what it cost.
 *
 *   php -S localhost:8000 -t examples/web-form
 *
 * On shared hosting, upload the project, keep vendor/ and var/ outside
 * public_html if you can, and point this file's require at the autoloader.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use PhpAiGuard\Exception\AiGuardException;
use PhpAiGuard\Exception\BudgetExceededException;
use PhpAiGuard\Exception\RateLimitExceededException;

session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
// In a real app this is the logged-in user's id; the session id stands in here.
$userId = 'visitor-' . substr(hash('sha256', session_id()), 0, 12);

$question = '';
$answer = null;
$error = null;
$cost = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question = trim((string) ($_POST['question'] ?? ''));
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($question === '' || mb_strlen($question) > 2000) {
        $error = 'Please enter a question (up to 2000 characters).';
    } else {
        try {
            $response = example_guard()->forUser($userId)->withFeature('web-form')->chat($question, [
                'system' => 'You are a helpful assistant for a small business website. Answer in under 120 words.',
                'max_tokens' => 300,
            ]);
            $answer = $response->text;
            $cost = $response->cacheHit ? 'free (answered from cache)' : sprintf('$%.5f', $response->cost);
        } catch (BudgetExceededException | RateLimitExceededException $e) {
            $error = $e->userMessage();           // friendly, safe to show
        } catch (AiGuardException $e) {
            error_log('AI error: ' . $e->getMessage());
            $error = 'The assistant is unavailable right now. Please try again later.';
        }
    }
}

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ask the assistant</title>
<style>
body { font: 16px/1.5 system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; color: #222; }
textarea { width: 100%; min-height: 110px; font: inherit; padding: 10px; box-sizing: border-box; }
button { font: inherit; padding: 8px 18px; margin-top: 8px; cursor: pointer; }
.answer { background: #f3f5ff; border-left: 4px solid #3b5bdb; padding: 12px 16px; white-space: pre-wrap; }
.error { background: #fff4f4; border-left: 4px solid #c92a2a; padding: 12px 16px; }
small { color: #666; }
</style>
</head>
<body>
<h1>Ask the assistant</h1>
<form method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <textarea name="question" maxlength="2000" required placeholder="e.g. What should I pack for Dubai in December?"><?= $h($question) ?></textarea>
    <button type="submit">Ask</button>
</form>
<?php if ($error !== null): ?>
    <p class="error"><?= $h($error) ?></p>
<?php elseif ($answer !== null): ?>
    <div class="answer"><?= $h($answer) ?></div>
    <p><small>Cost: <?= $h((string) $cost) ?></small></p>
<?php endif; ?>
</body>
</html>
