<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

final readonly class RefundPayment
{
    public function __construct(
        public string $paymentId,
        public int $expectedVersion,
        public string $actorId,
        public string $reason,
    ) {
    }
}
