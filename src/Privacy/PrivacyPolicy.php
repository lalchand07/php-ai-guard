<?php

declare(strict_types=1);

namespace PhpAiGuard\Privacy;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Provider\ProviderInterface;
use PhpAiGuard\Request;

/**
 * Decides, per provider, whether a request is sent as-is, redacted or not at all.
 *
 *     'privacy' => [
 *         'redact'        => false,      // redact for every provider
 *         'restore'       => true,       // put original values back into answers
 *         'patterns'      => ['mrz', 'iban', 'card', 'email', 'phone'],
 *         'custom'        => ['EMPLOYEE_ID' => '/\bEMP-\d{6}\b/'],
 *         'on_disallowed' => 'redact',   // or 'skip': never send to providers with allows_personal_data = false
 *     ]
 */
final class PrivacyPolicy
{
    public const REDACT = 'redact';
    public const SKIP = 'skip';

    public function __construct(
        private Redactor $redactor,
        private bool $redactAll = false,
        private bool $restore = true,
        private string $onDisallowed = self::REDACT
    ) {
        if (!in_array($onDisallowed, [self::REDACT, self::SKIP], true)) {
            throw new ConfigurationException('privacy.on_disallowed must be "redact" or "skip".');
        }
    }

    public function restores(): bool
    {
        return $this->restore;
    }

    /**
     * @return array{0: Request, 1: RedactionMap}|null null when the request must not be sent to this provider
     */
    public function prepare(Request $request, ProviderInterface $provider): ?array
    {
        $allowed = $provider->allowsPersonalData();
        if ($allowed && !$this->redactAll) {
            return [$request, new RedactionMap()];
        }
        if (!$allowed && !$this->redactAll && $this->onDisallowed === self::SKIP) {
            return $this->redactor->containsPersonalData($request->allText()) ? null : [$request, new RedactionMap()];
        }

        return $this->redactor->redactRequest($request);
    }
}
