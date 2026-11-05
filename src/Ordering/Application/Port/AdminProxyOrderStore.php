<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Dto\AdminProxyOrderData;

/**
 * DIP-003 — the admin proxy order write: order row, priced lines and the first audit entry,
 * one transaction (the bus boundary).
 */
interface AdminProxyOrderStore
{
    public function create(AdminProxyOrderData $data): void;
}
