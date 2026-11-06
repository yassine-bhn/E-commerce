<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Dto\ProductDraft;

final readonly class UpdateProduct
{
    public function __construct(
        public string $slug,
        public ProductDraft $draft,
        public string $actorId,
        public bool $actorIsSuperAdmin,
    ) {
    }
}
