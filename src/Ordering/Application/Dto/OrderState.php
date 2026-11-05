<?php

declare(strict_types=1);

namespace App\Ordering\Application\Dto;

use App\Ordering\Domain\Model\OrderStatus;

final readonly class OrderState
{
    public function __construct(
        public OrderStatus $status,
        public int $version,
    ) {
    }
}
