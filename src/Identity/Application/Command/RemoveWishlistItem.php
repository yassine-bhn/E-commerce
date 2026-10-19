<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class RemoveWishlistItem
{
    public function __construct(
        public string $customerId,
        public string $productSlug,
    ) {
    }
}
