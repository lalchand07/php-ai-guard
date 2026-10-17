<?php

/*
 * Travel agency example: turn a pasted WhatsApp message into validated JSON
 * (passenger names, routes and dates) ready to pre-fill a booking form.
 *
 * The phone number is redacted before it leaves your server (the Gemini/Groq
 * free tiers are marked allows_personal_data = false in bootstrap.php) and
 * put back into the result afterwards.
 *
 *   php examples/03-travel-whatsapp.php
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PhpAiGuard\Exception\AiGuardException;
use PhpAiGuard\Exception\JsonValidationException;

$whatsapp = <<<'MSG'
[12/10/26, 09:14] Priya Sharma: Hi! Need flights for me, my husband Rahul and our daughter Anaya (she's 7)
[12/10/26, 09:15] Priya Sharma: Delhi to Dubai on 20 Dec, coming back 28th
[12/10/26, 09:15] Priya Sharma: morning flights if possible 🙏 and a child seat
[12/10/26, 09:16] Priya Sharma: call me on +91 98765 43210 if anything
MSG;

$schema = [
    'type' => 'object',
    'required' => ['passengers', 'legs'],
    'additionalProperties' => false,
    'properties' => [
        'passengers' => [
            'type' => 'array',
            'minItems' => 1,
            'items' => [
                'type' => 'object',
                'required' => ['name', 'type'],
                'additionalProperties' => false,
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 2],
                    'type' => ['type' => 'string', 'enum' => ['adult', 'child', 'infant']],
                ],
            ],
        ],
        'legs' => [
            'type' => 'array',
            'minItems' => 1,
            'items' => [
                'type' => 'object',
                'required' => ['from', 'to', 'date'],
                'additionalProperties' => false,
                'properties' => [
                    'from' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'description' => 'IATA airport or city code'],
                    'to' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                    'date' => ['type' => 'string', 'format' => 'date'],
                ],
            ],
        ],
        'contact_phone' => ['type' => ['string', 'null']],
        'notes' => ['type' => ['string', 'null'], 'maxLength' => 300],
    ],
];

$ai = example_guard()->forUser('agent-7', 'sunrise-travel')->withFeature('whatsapp-extract');

try {
    $booking = $ai->json(
        "Extract the booking request from this WhatsApp message. Today is 2026-10-17; resolve relative dates to the next occurrence.\n\nWhatsApp chat:\n" . $whatsapp,
        $schema,
        ['max_tokens' => 600, 'temperature' => 0.0]
    );
} catch (JsonValidationException $e) {
    echo "The model could not produce valid JSON:\n- " . implode("\n- ", $e->getErrors()) . "\n";
    exit(1);
} catch (AiGuardException $e) {
    echo 'AI error: ', $e->getMessage(), "\n";
    exit(1);
}

foreach ($booking['passengers'] as $p) {
    printf("Passenger: %-20s (%s)\n", $p['name'], $p['type']);
}
foreach ($booking['legs'] as $leg) {
    printf("Flight:    %s → %s on %s\n", $leg['from'], $leg['to'], $leg['date']);
}
printf("Contact:   %s\nNotes:     %s\n", $booking['contact_phone'] ?? '-', $booking['notes'] ?? '-');
