<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

final readonly class UpdateCartItem
{
    public function __construct(
        public string $customerId,
        public string $itemId,
        public ?int $quantity,
        public ?string $variant,
    ) {
    }
}
