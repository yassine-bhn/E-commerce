<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

/**
 * One page of the storefront catalog browse (R4): every documented filter is optional,
 * `q` narrowing the page without turning the endpoint into the full-text search.
 */
final readonly class ListStorefrontProducts
{
    public const int DEFAULT_SIZE = 24;

    public const int MAX_SIZE = 100;

    public function __construct(
        public StorefrontProductFilters $filters = new StorefrontProductFilters(),
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
    ) {
    }
}
