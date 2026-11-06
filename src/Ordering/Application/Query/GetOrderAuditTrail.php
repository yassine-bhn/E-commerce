<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

final readonly class GetOrderAuditTrail
{
    public function __construct(public string $orderId)
    {
    }
}
