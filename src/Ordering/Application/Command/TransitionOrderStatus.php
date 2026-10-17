<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\Model\OrderStatus;

final readonly class TransitionOrderStatus
{
    public function __construct(
        public string $orderId,
        public int $expectedVersion,
        public OrderStatus $targetStatus,
        public string $changedBy,
        public string $reason,
    ) {
    }
}
