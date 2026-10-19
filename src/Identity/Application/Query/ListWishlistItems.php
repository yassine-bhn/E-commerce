<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

final readonly class ListWishlistItems
{
    public function __construct(public string $customerId)
    {
    }
}
