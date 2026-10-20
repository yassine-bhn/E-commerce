<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

final readonly class GetStorefrontCart
{
    public function __construct(
        public string $customerId,
    ) {
    }
}
