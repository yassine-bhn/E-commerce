<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The storefront catalog filters shared by the listing and the full-text search (R4, R5).
 *
 * `q` is the free-text term: mandatory for {@see SearchStorefrontProducts}, optional for
 * {@see ListStorefrontProducts} where it narrows the browse page.
 */
final readonly class StorefrontProductFilters
{
    public const array STOCKS = ['in', 'order', 'pickup', 'out'];

    public function __construct(
        public ?string $q = null,
        public ?string $categorySlug = null,
        public ?string $subcategory = null,
        public bool $promotionsOnly = false,
        public ?string $brand = null,
        public ?bool $isNew = null,
        public ?bool $isBest = null,
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public ?string $material = null,
        public ?string $stock = null,
        public ?string $sort = null,
    ) {
    }
}
