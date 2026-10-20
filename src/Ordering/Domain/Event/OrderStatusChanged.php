<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Event;

final readonly class OrderStatusChanged
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $fromStatus,
        public string $toStatus,
        public int $fromVersion,
        public int $toVersion,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
