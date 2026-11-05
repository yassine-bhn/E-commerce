<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
    case TND = 'TND';

    /**
     * Number of decimal digits in the currency's smallest unit. TND is a millime currency:
     * 15300 minor units is 15.3 DT; EUR, USD and GBP use two decimals.
     */
    public function fractionalDigits(): int
    {
        return match ($this) {
            self::TND => 3,
            self::EUR, self::USD, self::GBP => 2,
        };
    }
}
