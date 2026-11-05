<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\Model\Order;

/**
 * ISP-003 — the placement side of ordering: claim the idempotency key, then persist the order.
 * One adapter owns `processed_commands` and delegates the order write to the repository.
 */
interface OrderPlacementStore
{
    /**
     * Returns false when the key was already used — the command is a replay and the handler
     * must do nothing.
     */
    public function claim(string $key): bool;

    public function save(Order $order): void;
}
