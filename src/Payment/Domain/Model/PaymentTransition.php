<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

/**
 * One movement of a payment: the state it leaves, the state it reaches, and who asked.
 * It carries the version the caller observed, so the write can be guarded (RP1.1/RP1.2).
 */
final readonly class PaymentTransition
{
    public function __construct(
        public string $paymentId,
        public int $expectedVersion,
        public PaymentStatus $from,
        public PaymentStatus $to,
        public int $amountMinor,
        public string $actorId,
        public ?string $reason = null,
        public ?string $providerReference = null,
    ) {
    }
}
