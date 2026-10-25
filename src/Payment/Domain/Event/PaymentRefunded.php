<?php

declare(strict_types=1);

namespace App\Payment\Domain\Event;

final readonly class PaymentRefunded
{
    public function __construct(
        public string $eventId,
        public string $paymentId,
        public string $orderId,
        public int $amountMinor,
        public string $currency,
        public string $reason,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
