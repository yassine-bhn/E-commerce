<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class DeleteProduct
{
    public function __construct(
        public string $slug,
        public string $actorId,
    ) {
    }
}
