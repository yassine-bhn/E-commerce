<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class GetAdminProduct
{
    public function __construct(
        public string $slug,
        public ?string $requesterId,
        public bool $isAdmin,
    ) {
    }
}
