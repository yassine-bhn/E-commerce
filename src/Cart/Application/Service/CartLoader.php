<?php

declare(strict_types=1);

namespace App\Cart\Application\Service;

use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Repository\CartRepository;

final class CartLoader
{
    public function __construct(private CartRepository $carts)
    {
    }

    public function loadOrCreate(string $customerId): Cart
    {
        $cart = $this->carts->findByCustomerId($customerId);
        if (null !== $cart) {
            return $cart;
        }

        return Cart::create($customerId);
    }

    public function require(string $customerId): Cart
    {
        $cart = $this->carts->findByCustomerId($customerId);
        if (null === $cart) {
            throw new \InvalidArgumentException('Cart not found');
        }

        return $cart;
    }
}
