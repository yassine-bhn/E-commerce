<?php

declare(strict_types=1);

namespace App\Cart\Domain\Repository;

use App\Cart\Domain\Model\Cart;

interface CartRepository
{
    public function findByCustomerId(string $customerId): ?Cart;

    public function save(Cart $cart): void;
}
