<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

final readonly class GetStorefrontOrder
{
    public function __construct(
        public string $idOrNumber,
        public ?string $customerId = null,
    ) {
    }
}
