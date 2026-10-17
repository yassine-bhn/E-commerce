<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

final readonly class ListOrdersForCustomer
{
    public function __construct(public string $customerId)
    {
    }
}
