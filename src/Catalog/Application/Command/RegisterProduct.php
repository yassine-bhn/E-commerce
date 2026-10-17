<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class RegisterProduct
{
    public function __construct(
        public string $productId,
        public string $name,
        public int $priceMinor,
        public string $currency,
    ) {
    }
}
