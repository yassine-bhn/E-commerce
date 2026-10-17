<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
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
}
