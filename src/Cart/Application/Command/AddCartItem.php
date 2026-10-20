<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

final readonly class AddCartItem
{
    public function __construct(
        public string $customerId,
        public string $productSlug,
        public ?string $variant,
        public int $quantity,
    ) {
    }
}
