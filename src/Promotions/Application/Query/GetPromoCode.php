<?php

declare(strict_types=1);

namespace App\Promotions\Application\Query;

final readonly class GetPromoCode
{
    public function __construct(public string $code)
    {
    }
}
