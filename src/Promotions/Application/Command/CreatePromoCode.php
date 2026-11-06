<?php

declare(strict_types=1);

namespace App\Promotions\Application\Command;

final readonly class CreatePromoCode
{
    public function __construct(
        public string $code,
        public int $discountBasisPoints,
        public bool $active,
    ) {
    }
}
