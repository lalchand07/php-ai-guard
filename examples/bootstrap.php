<?php

/*
 * Shared setup for the examples.
 *
 * With ANTHROPIC_API_KEY and/or GEMINI_API_KEY / GROQ_API_KEY set, the
 * examples call the real APIs. Without any key they run in DEMO MODE: a fake
 * HTTP client returns canned answers so you can see budgets, logging and JSON
 * validation work without spending anything.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpAiGuard\AiGuard;
use PhpAiGuard\Http\CurlHttpClient;
use PhpAiGuard\Http\HttpRequest;
use PhpAiGuard\Http\HttpResponse;
use PhpAiGuard\Http\MockHttpClient;

function example_guard(): AiGuard
{
    $providers = [];
    $chain = [];
    if (getenv('ANTHROPIC_API_KEY')) {
        $providers['anthropic'] = ['api_key' => getenv('ANTHROPIC_API_KEY')];
        $chain[] = 'anthropic:claude-haiku-5-5';
    }
    if (getenv('GEMINI_API_KEY')) {
        // Free tier: never send it unredacted personal data.
        $providers['gemini'] = ['api_key' => getenv('GEMINI_API_KEY'), 'allows_personal_data' => false];
        $chain[] = 'gemini:gemini-3.8-flash';
    }
    if (getenv('GROQ_API_KEY')) {
        $providers['groq'] = ['api_key' => getenv('GROQ_API_KEY'), 'allows_personal_data' => false];
        $chain[] = 'groq:openai/gpt-oss-20b';
    }

    $demo = $providers === [];
    if ($demo) {
        // Marked like a free tier so the demo shows redaction + restoration.
        $providers['anthropic'] = ['api_key' => 'demo-key', 'allows_personal_data' => false];
        $chain[] = 'anthropic:claude-haiku-5-5';
    }

    @mkdir(__DIR__ . '/var', 0770, true);

    return AiGuard::create([
        'providers' => $providers,
        'tiers' => ['standard' => $chain],
        'pricing' => require __DIR__ . '/../config/pricing.example.php',
        'budgets' => [
            'global' => ['monthly' => 5.00],
            'user' => ['daily' => 0.05, 'monthly' => 0.50],
        ],
        'rate_limits' => ['user' => 10],
        'storage' => ['driver' => 'sqlite', 'path' => __DIR__ . '/var/ai-guard.sqlite'],
        'cache' => ['driver' => 'file', 'path' => __DIR__ . '/var/cache', 'ttl' => 3600],
        'http_client' => $demo ? demo_http_client() : new CurlHttpClient(),
    ]);
}

/**
 * Pretends to be the Anthropic API and answers from a few canned replies.
 */
function demo_http_client(): MockHttpClient
{
    $mock = new MockHttpClient();
    $reply = static function (HttpRequest $request): HttpResponse {
        $body = json_decode($request->body, true);
        $last = end($body['messages'])['content'] ?? '';
        $system = (string) ($body['system'] ?? '');

        if (str_contains($system, 'JSON schema') && str_contains($last, 'WhatsApp')) {
            $text = json_encode([
                'passengers' => [['name' => 'Priya Sharma', 'type' => 'adult'], ['name' => 'Rahul Sharma', 'type' => 'adult'], ['name' => 'Anaya Sharma', 'type' => 'child']],
                'legs' => [
                    ['from' => 'DEL', 'to' => 'DXB', 'date' => '2026-12-20'],
                    ['from' => 'DXB', 'to' => 'DEL', 'date' => '2026-12-28'],
                ],
                'contact_phone' => '[PHONE_1]',
                'notes' => 'Prefers morning flights, one child seat.',
            ]);
        } elseif (str_contains($system, 'JSON schema')) {
            $text = '{"sentiment": "positive", "confidence": 0.93}';
        } else {
            $text = "Demo mode: no API key is set, so this is a canned answer.\nYou asked: " . mb_substr($last, 0, 80);
        }

        return new HttpResponse(200, (string) json_encode([
            'id' => 'msg_demo',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $body['model'],
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => (int) (strlen($request->body) / 4), 'output_tokens' => (int) (strlen($text) / 4), 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]));
    };
    for ($i = 0; $i < 100; $i++) {
        $mock->queue($reply);
    }

    return $mock;
}
