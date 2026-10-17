<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\CategoryHierarchyCycleException;
use App\Catalog\Domain\Model\CategoryNotEmptyException;
use Doctrine\DBAL\Connection;

final class CategoryHierarchyValidator
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function validateParentAssignment(string $categorySlug, ?string $targetParentSlug): void
    {
        if (null === $targetParentSlug || '' === $targetParentSlug) {
            return;
        }

        if ($categorySlug === $targetParentSlug) {
            throw CategoryHierarchyCycleException::selfParent($categorySlug);
        }

        // Fetch all categories with their parent slugs to traverse ancestry
        $rows = $this->connection->fetchAllAssociative(
            'SELECT slug, parent_slug FROM storefront_category'
        );

        $parentMap = [];
        foreach ($rows as $row) {
            $parentMap[(string) $row['slug']] = isset($row['parent_slug']) ? (string) $row['parent_slug'] : null;
        }

        // Trace up from targetParentSlug to root. If categorySlug is in the ancestor chain, targetParentSlug is a descendant!
        $current = $targetParentSlug;
        $visited = [];

        while (null !== $current && '' !== $current) {
            if ($current === $categorySlug) {
                throw CategoryHierarchyCycleException::descendantCycle($categorySlug, $targetParentSlug);
            }

            if (isset($visited[$current])) {
                break; // safeguard against existing corrupt loops
            }
            $visited[$current] = true;

            $current = $parentMap[$current] ?? null;
        }
    }

    public function ensureCanDeleteCategory(string $categorySlug): void
    {
        /** @var array{subcategories_count: int|string, products_count: int|string}|false $counts */
        $counts = $this->connection->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM storefront_category WHERE parent_slug = :slug) AS subcategories_count,
                (SELECT COUNT(*) FROM storefront_product_view WHERE category_slug = :slug) AS products_count',
            ['slug' => $categorySlug],
        );

        $subCount = false !== $counts ? (int) $counts['subcategories_count'] : 0;
        $prodCount = false !== $counts ? (int) $counts['products_count'] : 0;

        if ($subCount > 0 || $prodCount > 0) {
            throw CategoryNotEmptyException::forCategory($categorySlug, $subCount, $prodCount);
        }
    }
}
