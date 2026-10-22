<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Shared\Application\Pagination\Page;

/**
 * One catalog page as the storefront receives it: the offset envelope plus the facet
 * distribution of the whole filtered result set (`null` when the SQL fallback served it).
 */
final readonly class StorefrontProductPage
{
    /**
     * @param array<string, array<string, int>>|null $facets
     */
    public function __construct(
        public Page $page,
        public ?array $facets,
    ) {
    }

    /**
     * @param callable(StorefrontProductView): array<string, mixed> $map
     *
     * @return array{items: list<array<string, mixed>>, meta: array{page: int, size: int, total: int, totalPages: int, hasMore: bool}, facets: array<string, array<string, int>>|null}
     */
    public function toArray(callable $map): array
    {
        $data = $this->page->toArray();
        $data['items'] = array_values(array_map($map, $this->page->items));
        $data['facets'] = $this->facets;

        return $data;
    }
}
