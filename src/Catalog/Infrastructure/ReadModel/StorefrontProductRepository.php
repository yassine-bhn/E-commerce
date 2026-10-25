<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\StorefrontProductFilters;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchQuery;
use App\Shared\Application\Pagination\Page;
use App\Shared\Application\Pagination\PageRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;

/**
 * Meilisearch-first catalog finder with a SQL fallback (ADR-0009, ECO-008).
 *
 * The index is only trusted for what its settings can express ({@see StorefrontProductSearchQuery});
 * everything else — promotions, `newest`, an unreachable Meilisearch, or an index that reports
 * zero hits for a query-less browse while the catalog has products — is served by
 * {@see self::viaSql()} so the storefront never shows a silently empty catalog.
 */
final class StorefrontProductRepository
{
    private const string SELECT = 'SELECT id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor,
        currency, stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
        FROM storefront_product_view';

    private const string SEARCH_PREDICATE = " (name ILIKE :q ESCAPE '\\' OR brand ILIKE :q ESCAPE '\\'
            OR ref ILIKE :q ESCAPE '\\' OR subcategory ILIKE :q ESCAPE '\\' OR category_slug ILIKE :q ESCAPE '\\')";

    public function __construct(
        private readonly Connection $connection,
        private readonly Client $client,
    ) {
    }

    public function browse(StorefrontProductFilters $filters, PageRequest $page): StorefrontProductPage
    {
        if (StorefrontProductSearchQuery::indexable($filters)) {
            $indexed = $this->viaIndex($filters, $page);

            // A query-less browse must never come back empty from a cold/partial index.
            if (null !== $indexed && ($indexed->page->total > 0 || null !== $filters->q)) {
                return $indexed;
            }
        }

        return $this->viaSql($filters, $page);
    }

    public function search(StorefrontProductFilters $filters, PageRequest $page): StorefrontProductPage
    {
        if (StorefrontProductSearchQuery::indexable($filters)) {
            $indexed = $this->viaIndex($filters, $page);
            if (null !== $indexed) {
                // Zero hits on a non-empty term is a valid answer: no SQL fallback (R5).
                return $indexed;
            }
        }

        return $this->viaSql($filters, $page);
    }

    public function bySlug(string $slug): ?StorefrontProductView
    {
        $row = $this->connection->fetchAssociative(
            self::SELECT.' WHERE slug = :slug AND'.CatalogVisibility::PRODUCT_PUBLIC,
            ['slug' => $slug],
        );
        if (false === $row) {
            return null;
        }

        return StorefrontProductView::fromRow($row);
    }

    /**
     * spec-run-8 R15 — the Super Admin reads the record whatever its approval and publication
     * state, so the admin surface never goes through the public predicate.
     */
    public function adminBySlug(string $slug): ?StorefrontProductView
    {
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE slug = :slug', ['slug' => $slug]);
        if (false === $row) {
            return null;
        }

        return StorefrontProductView::fromRow($row);
    }

    private function viaIndex(StorefrontProductFilters $filters, PageRequest $page): ?StorefrontProductPage
    {
        $searchParams = [
            'sort' => StorefrontProductSearchQuery::sort($filters->sort),
            'page' => $page->effectivePage(),
            'hitsPerPage' => $page->effectiveSize(),
            'facets' => StorefrontProductSearchQuery::FACETS,
            'attributesToRetrieve' => ['slug'],
        ];

        $filter = StorefrontProductSearchQuery::filter($filters);
        if (null !== $filter) {
            $searchParams['filter'] = $filter;
        }

        try {
            $result = $this->client
                ->index(StorefrontProductSearchIndexer::INDEX_NAME)
                ->search($filters->q ?? '', $searchParams);
        } catch (\Throwable) {
            return null;
        }

        $slugs = [];
        foreach ($result->getHits() as $hit) {
            if (is_string($hit['slug'] ?? null)) {
                $slugs[] = $hit['slug'];
            }
        }

        $total = $result->getTotalHits() ?? $result->getEstimatedTotalHits() ?? 0;

        return new StorefrontProductPage(
            new Page(
                $this->bySlugs($slugs),
                $page->effectivePage(),
                $page->effectiveSize(),
                $total,
            ),
            self::normaliseFacets($result->getFacetDistribution()),
        );
    }

    /**
     * The documented facet set, always all five keys and always in the documented order,
     * so clients never depend on the engine's own iteration order.
     *
     * @param array<string, array<string, int>> $distribution
     *
     * @return array<string, array<string, int>>
     */
    private static function normaliseFacets(array $distribution): array
    {
        $facets = [];
        foreach (StorefrontProductSearchQuery::FACETS as $attribute) {
            $values = [];
            foreach ($distribution[$attribute] ?? [] as $label => $count) {
                $values[(string) $label] = (int) $count;
            }
            $facets[$attribute] = $values;
        }

        return $facets;
    }

    private function viaSql(StorefrontProductFilters $filters, PageRequest $page): StorefrontProductPage
    {
        [$where, $params] = $this->sqlConditions($filters);

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM storefront_product_view'.$where, $params);

        $rows = $this->connection->fetchAllAssociative(
            self::SELECT.$where
            .' ORDER BY '.self::sqlOrderBy($filters->sort)
            .' LIMIT '.$page->effectiveSize()
            .' OFFSET '.$page->effectiveOffset(),
            $params,
        );

        return new StorefrontProductPage(
            new Page(
                array_map(StorefrontProductView::fromRow(...), $rows),
                $page->effectivePage(),
                $page->effectiveSize(),
                $total,
            ),
            null,
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function sqlConditions(StorefrontProductFilters $filters): array
    {
        $conditions = [CatalogVisibility::PRODUCT_PUBLIC];
        $params = [];

        if (null !== $filters->q) {
            $conditions[] = self::SEARCH_PREDICATE;
            $params['q'] = '%'.self::escapeLike($filters->q).'%';
        }

        if ($filters->promotionsOnly) {
            $conditions[] = 'old_price_minor IS NOT NULL';
        }

        if (null !== $filters->categorySlug) {
            $conditions[] = 'category_slug = :category';
            $params['category'] = $filters->categorySlug;
        }

        if (null !== $filters->subcategory) {
            $conditions[] = 'subcategory = :sub';
            $params['sub'] = $filters->subcategory;
        }

        if (null !== $filters->brand) {
            $conditions[] = 'brand = :brand';
            $params['brand'] = $filters->brand;
        }

        if (true === $filters->isNew) {
            $conditions[] = 'is_new = true';
        }

        if (false === $filters->isNew) {
            $conditions[] = 'is_new = false';
        }

        if (true === $filters->isBest) {
            $conditions[] = 'is_best = true';
        }

        if (false === $filters->isBest) {
            $conditions[] = 'is_best = false';
        }

        if (null !== $filters->priceMin) {
            $conditions[] = 'price_minor >= :price_min';
            $params['price_min'] = $filters->priceMin;
        }

        if (null !== $filters->priceMax) {
            $conditions[] = 'price_minor <= :price_max';
            $params['price_max'] = $filters->priceMax;
        }

        if (null !== $filters->material) {
            $conditions[] = 'material = :material';
            $params['material'] = $filters->material;
        }

        if (null !== $filters->stock) {
            $conditions[] = 'stock = :stock';
            $params['stock'] = $filters->stock;
        }

        return [' WHERE '.implode(' AND ', $conditions), $params];
    }

    private static function sqlOrderBy(?string $sort): string
    {
        return match ($sort) {
            'price_asc' => 'price_minor ASC, slug ASC',
            'price_desc' => 'price_minor DESC, slug ASC',
            'rating' => 'rating DESC, reviews DESC, slug ASC',
            'newest' => 'is_new DESC, name ASC, slug ASC',
            default => 'name ASC, slug ASC',
        };
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<StorefrontProductView>
     */
    private function bySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            self::SELECT.' WHERE slug IN (:slugs) AND'.CatalogVisibility::PRODUCT_PUBLIC,
            ['slugs' => $slugs],
            ['slugs' => ArrayParameterType::STRING],
        );

        $bySlug = [];
        foreach ($rows as $row) {
            $view = StorefrontProductView::fromRow($row);
            $bySlug[$view->slug] = $view;
        }

        // Preserve the ranking Meilisearch returned.
        $items = [];
        foreach ($slugs as $slug) {
            if (isset($bySlug[$slug])) {
                $items[] = $bySlug[$slug];
            }
        }

        return $items;
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
