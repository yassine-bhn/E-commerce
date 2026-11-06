<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * The sub-category picture writes: set/clear the `image` key of one node of the category's
 * `subcategories` JSON tree. The policy is the same gate as every other taxonomy write, the
 * row update and the one audit entry per success land in the transaction the command-bus
 * middleware opened. An unknown category answers CategoryNotFoundException, an unknown label
 * path answers SubcategoryNotFoundException — before anything is written.
 */
interface SubcategoryImageStore
{
    /**
     * @param list<string> $labelPath
     */
    public function setSubcategoryImage(string $slug, array $labelPath, string $imagePath, ?string $actorId, bool $actorIsSuperAdmin): void;

    /**
     * @param list<string> $labelPath
     */
    public function removeSubcategoryImage(string $slug, array $labelPath, ?string $actorId, bool $actorIsSuperAdmin): void;
}
