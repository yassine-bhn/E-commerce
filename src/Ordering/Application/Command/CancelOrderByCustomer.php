<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

final readonly class CancelOrderByCustomer
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public int $expectedVersion,
    ) {
    }
}
