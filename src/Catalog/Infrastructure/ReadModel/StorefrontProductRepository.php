<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use Doctrine\DBAL\Connection;
use Meilisearch\Client;

final class StorefrontProductRepository
{
    private const string SELECT = 'SELECT id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor,
        currency, stock, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
        FROM storefront_product_view';

    public function __construct(
        private Connection $connection,
        private string $meilisearchUrl,
    ) {
    }

    /** @return list<StorefrontProductView> */
    public function list(
        ?string $categorySlug = null,
        ?string $subcategory = null,
        bool $promotionsOnly = false,
        ?string $brand = null,
        ?bool $isNew = null,
        ?bool $isBest = null,
        ?int $limit = null,
        ?string $sort = null,
    ): array {
        $sql = self::SELECT;
        $conditions = [];
        $params = [];

        if ($promotionsOnly) {
            $conditions[] = 'old_price_minor IS NOT NULL';
        }
        if (null !== $categorySlug) {
            $conditions[] = 'category_slug = :category';
            $params['category'] = $categorySlug;
        }
        if (null !== $subcategory) {
            $conditions[] = 'subcategory = :sub';
            $params['sub'] = $subcategory;
        }
        if (null !== $brand) {
            $conditions[] = 'brand = :brand';
            $params['brand'] = $brand;
        }
        if (true === $isNew) {
            $conditions[] = 'is_new = true';
        }
        if (true === $isBest) {
            $conditions[] = 'is_best = true';
        }

        if ([] !== $conditions) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        $orderBy = match ($sort) {
            'price_asc' => 'price_minor ASC',
            'price_desc' => 'price_minor DESC',
            'rating' => 'rating DESC, reviews DESC',
            'newest' => 'is_new DESC, name ASC',
            default => 'name ASC',
        };
        $sql .= ' ORDER BY '.$orderBy;

        if (null !== $limit && $limit > 0) {
            $sql .= ' LIMIT '.(int) $limit;
        }

        return array_map(
            StorefrontProductView::fromRow(...),
            $this->connection->fetchAllAssociative($sql, $params),
        );
    }

    public function bySlug(string $slug): ?StorefrontProductView
    {
        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE slug = :slug', ['slug' => $slug]);
        if (false === $row) {
            return null;
        }

        return StorefrontProductView::fromRow($row);
    }

    /** @return list<StorefrontProductView> */
    public function search(string $query, int $limit): array
    {
        $term = trim($query);
        if ('' === $term) {
            return [];
        }

        try {
            $client = new Client($this->meilisearchUrl);
            $result = $client->index('storefront_products')->search($term, ['limit' => $limit]);
            $hits = $result->getHits();
            if ([] !== $hits) {
                $slugs = array_values(array_unique(array_map(static fn (array $hit): string => (string) $hit['slug'], $hits)));
                $products = [];
                foreach ($slugs as $slug) {
                    $product = $this->bySlug($slug);
                    if (null !== $product) {
                        $products[] = $product;
                    }
                }

                return $products;
            }
        } catch (\Throwable) {
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($term)).'%';

        return array_map(
            StorefrontProductView::fromRow(...),
            $this->connection->fetchAllAssociative(
                self::SELECT." WHERE lower(name || ' ' || brand || ' ' || ref || ' ' || subcategory || ' ' || category_slug) LIKE :q ESCAPE '\\' ORDER BY name ASC LIMIT ".$limit,
                ['q' => $like],
            ),
        );
    }
}
