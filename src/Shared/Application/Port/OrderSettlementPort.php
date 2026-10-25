<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Payment\OrderNotPayableException;
use App\Shared\Domain\Payment\OrderNotRefundableException;

interface OrderSettlementPort
{
    /**
     * Moves an order from awaiting payment to paid and records the transition in its audit trail.
     *
     * @throws OrderNotPayableException when the order is not awaiting payment anymore
     */
    public function markPaid(string $orderId, string $actorId, string $reason): void;

    /**
     * Moves a paid or shipped order to refunded and records the transition in its audit trail.
     *
     * @throws OrderNotRefundableException when the order is in a state that cannot be refunded
     */
    public function markRefunded(string $orderId, string $actorId, string $reason): void;
}
