<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

/**
 * ADR-0001 — the ledger is append-only and it is the audit spine of the Payment context.
 * Applying a transition is one operation: the payment row moves and the ledger gains a row,
 * so no caller can bump the state without leaving the trace (RP1.6).
 */
interface PaymentLedger
{
    /**
     * @throws PaymentVersionConflictException when the guarded update matched no row
     */
    public function record(PaymentTransition $transition): void;
}
