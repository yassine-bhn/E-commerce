<?php

declare(strict_types=1);

namespace App\Shared\Domain;

enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
    case TND = 'TND';
}
