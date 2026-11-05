<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\CategoryHierarchyCycleException;

/**
 * Pure hierarchy rule (solid Task 3.2): given the parent map of the whole taxonomy, refuses a
 * parent assignment that would make a category its own ancestor. The SQL moved to
 * DbalCategoryStore; this service owns only the traversal.
 *
 * @param array<string, string|null> $parentMap
 */
final class CategoryHierarchy
{
    public function assertAcyclic(string $categorySlug, ?string $targetParentSlug, array $parentMap): void
    {
        if (null === $targetParentSlug || '' === $targetParentSlug) {
            return;
        }

        if ($categorySlug === $targetParentSlug) {
            throw CategoryHierarchyCycleException::selfParent($categorySlug);
        }

        // Trace up from targetParentSlug to root. If categorySlug is in the ancestor chain,
        // targetParentSlug is a descendant!
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
}
