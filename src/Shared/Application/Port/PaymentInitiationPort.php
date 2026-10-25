<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface PaymentInitiationPort
{
    /**
     * Opens a pending payment for an order and appends its opening ledger entry.
     *
     * The amount is the order total captured at placement (RP1.5): the payment never
     * re-derives it, so a later catalog or promo change cannot move the debt.
     */
    public function open(string $orderId, string $method, int $amountMinor, string $currency, string $actorId): void;
}
