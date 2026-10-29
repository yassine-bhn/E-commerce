<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAddSameCurrency(): void
    {
        $a = Money::ofMinor(100, Currency::EUR);
        $b = Money::ofMinor(250, Currency::EUR);
        self::assertTrue($a->add($b)->equals(Money::ofMinor(350, Currency::EUR)));
    }

    public function testCurrencyMismatchThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::ofMinor(100, Currency::EUR)->add(Money::ofMinor(1, Currency::USD));
    }

    /** @return iterable<string, array{string, int}> */
    public static function tndDecimals(): iterable
    {
        yield 'one decimal' => ['15.3', 15300];
        yield 'two decimals' => ['153894.32', 153894320];
        yield 'whole dinars' => ['680', 680000];
        yield 'one millime' => ['0.001', 1];
    }

    #[DataProvider('tndDecimals')]
    public function testFromDecimalStringParsesTndMillimesExactly(string $decimal, int $millimes): void
    {
        self::assertSame($millimes, Money::fromDecimalString($decimal, Currency::TND)->minorUnits());
    }

    public function testFromDecimalStringUsesTheCurrencyScale(): void
    {
        self::assertSame(1530, Money::fromDecimalString('15.3', Currency::EUR)->minorUnits());
        self::assertSame(1530, Money::fromDecimalString('15.3', Currency::USD)->minorUnits());
        self::assertSame(1530, Money::fromDecimalString('15.3', Currency::GBP)->minorUnits());
    }

    public function testFromDecimalStringRejectsMoreFractionalDigitsThanTheCurrencyAllows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimalString('1.2345', Currency::TND);
    }

    /** @return iterable<string, array{string, Currency}> */
    public static function malformedDecimals(): iterable
    {
        yield 'negative' => ['-1.5', Currency::TND];
        yield 'empty' => ['', Currency::TND];
        yield 'not numeric' => ['abc', Currency::TND];
        yield 'leading dot' => ['.5', Currency::TND];
        yield 'trailing dot' => ['15.', Currency::TND];
        yield 'two dots' => ['1.2.3', Currency::TND];
        yield 'scientific notation' => ['1e3', Currency::TND];
        yield 'spaces' => [' 15.3 ', Currency::TND];
        yield 'too many EUR decimals' => ['15.300', Currency::EUR];
    }

    #[DataProvider('malformedDecimals')]
    public function testFromDecimalStringRejectsMalformedInput(string $decimal, Currency $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimalString($decimal, $currency);
    }

    public function testToDecimalStringDropsUnnecessaryTrailingZeros(): void
    {
        self::assertSame('15.3', Money::ofMinor(15300, Currency::TND)->toDecimalString());
        self::assertSame('680', Money::ofMinor(680000, Currency::TND)->toDecimalString());
        self::assertSame('153894.32', Money::ofMinor(153894320, Currency::TND)->toDecimalString());
        self::assertSame('0.001', Money::ofMinor(1, Currency::TND)->toDecimalString());
        self::assertSame('15.3', Money::ofMinor(1530, Currency::EUR)->toDecimalString());
    }
}
