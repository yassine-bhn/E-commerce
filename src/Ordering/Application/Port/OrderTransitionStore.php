<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\Model\OrderStatus;

/**
 * DIP-003 — the guarded status write (optimistic version check) and its audit trail row.
 */
interface OrderTransitionStore
{
    /**
     * Moves the order to the target status only when the stored version still matches; answers
     * whether the write happened.
     */
    public function transition(string $orderId, int $expectedVersion, OrderStatus $target, int $newVersion): bool;

    public function appendAudit(
        string $orderId,
        OrderStatus $from,
        OrderStatus $to,
        int $fromVersion,
        int $toVersion,
        string $changedBy,
        string $reason,
    ): void;
}
