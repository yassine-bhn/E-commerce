<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ListStorefrontProducts
{
    public function __construct(
        public ?string $categorySlug = null,
        public ?string $subcategory = null,
        public bool $promotionsOnly = false,
        public ?string $brand = null,
        public ?bool $isNew = null,
        public ?bool $isBest = null,
        public ?int $limit = null,
        public ?string $sort = null,
    ) {
    }
}
