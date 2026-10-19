<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class AddWishlistItem
{
    public function __construct(
        public string $customerId,
        public string $productSlug,
    ) {
    }
}
