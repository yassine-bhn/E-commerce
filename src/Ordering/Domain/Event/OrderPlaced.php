<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

final readonly class OrderPlaced
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $customerId,
        public string $orderNumber,
        public int $totalMinor,
        public string $currency,
        public string $orderSource,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
