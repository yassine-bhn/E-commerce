<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

final readonly class ProductView
{
    public function __construct(
        public string $id,
        public string $name,
        public int $priceMinor,
        public string $currency,
    ) {
    }
}
