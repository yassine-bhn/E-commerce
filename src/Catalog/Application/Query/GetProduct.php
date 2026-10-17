<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class GetProduct
{
    public function __construct(
        public string $productId,
        public string $requesterId,
        public bool $isAdmin,
    ) {
    }
}
