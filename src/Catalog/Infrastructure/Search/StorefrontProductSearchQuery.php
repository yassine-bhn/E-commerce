<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Query\StorefrontProductFilters;

/**
 * Translates the storefront filters into the Meilisearch `filter` and `sort` expressions
 * declared by ADR-0009 / {@see StorefrontProductSearchIndexer} settings.
 *
 * Only the attributes declared filterable/sortable in the index settings may appear here;
 * a request that needs an attribute outside those lists (`promotions` on `old_price_minor`,
 * `newest` on `is_new`) is reported as not indexable so the caller serves it with SQL instead
 * of silently ignoring the filter.
 */
final class StorefrontProductSearchQuery
{
    /** Facet distribution requested with every catalog page (R4). */
    public const array FACETS = ['brand', 'category_slug', 'subcategory', 'stock', 'material'];

    /** @var array<string, list<string>> */
    private const array SORTS = [
        'name' => ['name:asc'],
        'price_asc' => ['price_minor:asc'],
        'price_desc' => ['price_minor:desc'],
        'rating' => ['rating:desc', 'reviews:desc'],
    ];

    public static function filter(StorefrontProductFilters $filters): ?string
    {
        $expressions = [];

        foreach (
            [
                'category_slug' => $filters->categorySlug,
                'subcategory' => $filters->subcategory,
                'brand' => $filters->brand,
            ] as $attribute => $value
        ) {
            if (null !== $value && '' !== $value) {
                $expressions[] = $attribute.' = "'.self::escape($value).'"';
            }
        }

        if (null !== $filters->isNew) {
            $expressions[] = 'is_new = '.self::boolean($filters->isNew);
        }

        if (null !== $filters->isBest) {
            $expressions[] = 'is_best = '.self::boolean($filters->isBest);
        }

        if (null !== $filters->priceMin) {
            $expressions[] = 'price_minor >= '.$filters->priceMin;
        }

        if (null !== $filters->priceMax) {
            $expressions[] = 'price_minor <= '.$filters->priceMax;
        }

        foreach (['material' => $filters->material, 'stock' => $filters->stock] as $attribute => $value) {
            if (null !== $value && '' !== $value) {
                $expressions[] = $attribute.' = "'.self::escape($value).'"';
            }
        }

        return [] === $expressions ? null : implode(' AND ', $expressions);
    }

    /**
     * @return list<string>
     */
    public static function sort(?string $sort): array
    {
        return self::SORTS[$sort ?? 'name'] ?? self::SORTS['name'];
    }

    /**
     * Whether the index can honour every part of the request; when it cannot the SQL fallback
     * serves the page so no requested filter is silently dropped.
     */
    public static function indexable(StorefrontProductFilters $filters): bool
    {
        if ($filters->promotionsOnly) {
            return false;
        }

        return !isset($filters->sort) || 'newest' !== $filters->sort;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private static function boolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
