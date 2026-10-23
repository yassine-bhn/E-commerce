<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

final readonly class AutoCancelOrder
{
    public function __construct(public string $orderId)
    {
    }
}
