<?php

declare(strict_types=1);

namespace App\Cart\Domain\Event;

final readonly class CartCreated
{
    public function __construct(
        public string $cartId,
        public string $customerId,
    ) {
    }
}
