<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function testTndUsesMillimes(): void
    {
        self::assertSame(3, Currency::TND->fractionalDigits());
    }

    public function testOtherCurrenciesKeepTwoFractionalDigits(): void
    {
        self::assertSame(2, Currency::EUR->fractionalDigits());
        self::assertSame(2, Currency::USD->fractionalDigits());
        self::assertSame(2, Currency::GBP->fractionalDigits());
    }
}
