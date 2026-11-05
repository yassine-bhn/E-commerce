<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Dto\OrderState;

/**
 * DIP-003 — reads an order's status/version pair for the transition guards.
 */
interface OrderStateReader
{
    public function find(string $orderId): ?OrderState;
}
