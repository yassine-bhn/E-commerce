<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\WhatsApp;

use App\Shared\Domain\WhatsApp\WhatsAppNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppNumberTest extends TestCase
{
    #[DataProvider('acceptedNumberProvider')]
    public function testNormalisesConfiguredNumberToDigits(string $raw, string $expectedDigits): void
    {
        self::assertSame($expectedDigits, WhatsAppNumber::fromString($raw)->digits());
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedNumberProvider(): iterable
    {
        yield 'configured placeholder, digits only' => ['21600000000', '21600000000'];
        yield 'international format, single leading plus stripped' => ['+21612345678', '21612345678'];
        yield 'exactly eight digits, the accepted minimum' => ['12345678', '12345678'];
        yield 'exactly fifteen digits, the accepted maximum' => ['123456789012345', '123456789012345'];
    }

    #[DataProvider('unparseableNumberProvider')]
    public function testRejectsUnparseableNumber(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // R4/R14 — the refusal names the rule and never the value: this message is logged and, in
        // debug, rendered into the error page, while a mistyped key can hold anything.
        $this->expectExceptionMessage('WhatsApp number is not 8 to 15 digits, optionally prefixed by a single "+".');

        WhatsAppNumber::fromString($raw);
    }

    /** @return iterable<string, array{string}> */
    public static function unparseableNumberProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['216XXXXXXXX'];
        yield 'spaces' => ['216 12 345 678'];
        yield 'dashes after a leading plus' => ['+216-12-345-678'];
        yield 'parentheses' => ['(216)12345678'];
        yield 'double plus' => ['++21612345678'];
        yield 'plus outside the leading position' => ['216+12345678'];
        yield 'seven digits, below the eight digit minimum' => ['1234567'];
        yield 'sixteen digits, above the fifteen digit maximum' => ['1234567890123456'];
        yield 'trailing whitespace' => ['21612345678 '];
    }
}
