<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

/**
 * One page of the storefront full-text search (R5): `q` is mandatory (the HTTP layer
 * rejects a blank term), the filters and the page contract are the listing's.
 */
final readonly class SearchStorefrontProducts
{
    public const int DEFAULT_SIZE = 24;

    public const int MAX_SIZE = 100;

    public function __construct(
        public StorefrontProductFilters $filters = new StorefrontProductFilters(),
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
    ) {
    }
}
