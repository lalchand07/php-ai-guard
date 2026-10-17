<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Privacy;

use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Privacy\Pattern\CardNumberPattern;
use PhpAiGuard\Privacy\Pattern\IbanPattern;
use PhpAiGuard\Privacy\Pattern\MrzPattern;
use PhpAiGuard\Privacy\Pattern\PhonePattern;
use PhpAiGuard\Privacy\RedactionMap;
use PhpAiGuard\Privacy\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    private function redact(string $text, ?RedactionMap $map = null): string
    {
        return Redactor::create()->redact($text, $map ?? new RedactionMap());
    }

    public function testEmails(): void
    {
        self::assertSame('Write to [EMAIL_1] or [EMAIL_2].', $this->redact('Write to a.b+tag@mail.example.co.uk or x_y@test.io.'));
        self::assertSame('Version 1.2@beta stays', $this->redact('Version 1.2@beta stays'));
    }

    /**
     * @dataProvider phones
     */
    public function testPhones(string $text, bool $redacted): void
    {
        self::assertSame($redacted, str_contains($this->redact($text), '[PHONE_1]'), $text);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function phones(): iterable
    {
        yield 'UK international' => ['Call +44 20 7946 0958 now', true];
        yield 'US formatted' => ['Call (415) 555-0132 today', true];
        yield 'India mobile' => ['WhatsApp +91 98765 43210', true];
        yield '00 prefix' => ['Ring 0033 1 23 45 67 89', true];
        yield 'national' => ['Tel 020 7946 0958', true];
        yield 'ISO date' => ['Flight on 2026-10-17', false];
        yield 'EU date' => ['Flight on 17/10/2026', false];
        yield 'short number' => ['Room 1234, flight BA 286', false];
        yield 'time range' => ['Open 09:00-17:30', false];
    }

    public function testCardsNeedAValidLuhnChecksum(): void
    {
        self::assertSame('Pay with [CARD_1]', $this->redact('Pay with 4111 1111 1111 1111'));
        self::assertSame('Pay with [CARD_1]', $this->redact('Pay with 5500-0000-0000-0004'));
        self::assertTrue(CardNumberPattern::isValid('378282246310005')); // Amex, 15 digits
        self::assertFalse(CardNumberPattern::isValid('4111 1111 1111 1112'));
        self::assertStringNotContainsString('[CARD', $this->redact('Order 4111111111111112 shipped'));
    }

    public function testIbans(): void
    {
        self::assertTrue(IbanPattern::isValid('GB82 WEST 1234 5698 7654 32'));
        self::assertTrue(IbanPattern::isValid('DE89370400440532013000'));
        self::assertFalse(IbanPattern::isValid('GB82 WEST 1234 5698 7654 33'));
        self::assertSame('IBAN: [IBAN_1].', $this->redact('IBAN: DE89 3704 0044 0532 0130 00.'));
    }

    public function testPassportMrz(): void
    {
        // ICAO 9303 specimen passport.
        $mrz = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";
        self::assertSame("[MRZ_1]\n[MRZ_2]", $this->redact($mrz));
        self::assertTrue(MrzPattern::isValid('L898902C36UTO7408122F1204159ZE184226B<<<<<10'));
        self::assertFalse(MrzPattern::isValid('L898902C37UTO7408122F1204159ZE184226B<<<<<10'), 'wrong document check digit');
    }

    public function testIdCardMrz(): void
    {
        // ICAO 9303 specimen TD1 ID card.
        $mrz = "I<UTOD231458907<<<<<<<<<<<<<<<\n7408122F1204159UTO<<<<<<<<<<<6\nERIKSSON<<ANNA<MARIA<<<<<<<<<<";
        self::assertSame("[MRZ_1]\n[MRZ_2]\n[MRZ_3]", $this->redact($mrz));
    }

    public function testPlaceholdersAreStableAndRestorable(): void
    {
        $map = new RedactionMap();
        $text = $this->redact('a@x.io, b@x.io and again a@x.io', $map);
        self::assertSame('[EMAIL_1], [EMAIL_2] and again [EMAIL_1]', $text);
        self::assertSame('a@x.io, b@x.io and again a@x.io', $map->restore($text));
        self::assertSame(['to' => ['a@x.io']], $map->restoreValue(['to' => ['[EMAIL_1]']]));
        self::assertSame(2, $map->count());
    }

    public function testCustomPatternsAndSelection(): void
    {
        $redactor = Redactor::create(['email'], ['label' => ['label' => 'ORDER_ID', 'regex' => '/ORD-\d+/']]);
        $map = new RedactionMap();
        self::assertSame('[ORDER_ID_1] for [EMAIL_1], call +44 20 7946 0958', $redactor->redact('ORD-991 for jo@x.io, call +44 20 7946 0958', $map));
        self::assertTrue($redactor->containsPersonalData('jo@x.io'));
        self::assertFalse($redactor->containsPersonalData('nothing here'));
    }

    public function testInvalidConfig(): void
    {
        $this->expectException(ConfigurationException::class);
        Redactor::create(['ssn']);
    }

    public function testPhoneValidatorRejectsLongPlainIds(): void
    {
        self::assertFalse(PhonePattern::isValid('123456789012345'));
        self::assertTrue(PhonePattern::isValid('+1 415 555 0132'));
    }
}
