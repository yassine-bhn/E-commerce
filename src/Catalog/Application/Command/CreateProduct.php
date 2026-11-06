<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Dto\ProductDraft;

final readonly class CreateProduct
{
    public function __construct(
        public ProductDraft $draft,
        public string $actorId,
    ) {
    }
}
