<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Support;

use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Repository\CartRepository;

final class InMemoryCartRepository implements CartRepository
{
    /** @var array<string, Cart> */
    public array $carts = [];

    public function findByCustomerId(string $customerId): ?Cart
    {
        return $this->carts[$customerId] ?? null;
    }

    public function save(Cart $cart): void
    {
        $this->carts[$cart->customerId()] = $cart;
    }
}
