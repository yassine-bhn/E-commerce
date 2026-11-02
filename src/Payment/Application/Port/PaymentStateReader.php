<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Application\Dto\PaymentSnapshot;

/**
 * DIP-003 — reads a payment's guard state (status, version, amount) for the confirm/refund
 * transitions.
 */
interface PaymentStateReader
{
    public function find(string $paymentId): ?PaymentSnapshot;
}
