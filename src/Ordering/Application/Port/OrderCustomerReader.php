<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Dto\OrderCustomer;

/**
 * DIP-003 — reads the owner and the status of an order for the customer-cancel guard.
 */
interface OrderCustomerReader
{
    public function find(string $orderId): ?OrderCustomer;
}
