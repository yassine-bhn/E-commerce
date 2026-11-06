<?php

declare(strict_types=1);

namespace App\Promotions\Application\Command;

final readonly class DeletePromoCode
{
    public function __construct(public string $code)
    {
    }
}
