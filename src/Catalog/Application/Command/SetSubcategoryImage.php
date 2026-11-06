<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Attach or replace the picture of one sub-category node, addressed by its label path inside
 * the category's `subcategories` tree. The reference rule is the domain value object's and the
 * policy, row update and audit entry are the store's (the brand-image shape, R3/R5 of the
 * picture CRUD).
 */
final class SetSubcategoryImage
{
    /**
     * @param list<string> $labelPath
     */
    public function __construct(
        public readonly string $slug,
        public readonly array $labelPath,
        public readonly string $image,
        public readonly ?string $actorId,
        public readonly bool $actorIsSuperAdmin,
    ) {
    }
}
