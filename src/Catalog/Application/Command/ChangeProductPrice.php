<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class ChangeProductPrice
{
    public function __construct(
        public string $productId,
        public int $priceMinor,
        public string $currency,
    ) {
    }
}
