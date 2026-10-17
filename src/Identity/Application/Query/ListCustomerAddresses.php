<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

final readonly class ListCustomerAddresses
{
    public function __construct(public string $customerId)
    {
    }
}
