<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The category list the admin dashboard edits (spec R8): every category, hidden ones
 * included, with its sub-levels and product count.
 *
 * Like every storefront read in this repository it carries the requester identity and the
 * resolved role, and {@see \App\Catalog\Infrastructure\ReadModel\CategoryFinder} turns them
 * into the rows the database is allowed to hand back (QRY-004).
 */
final readonly class ListCategories
{
    public function __construct(
        public string $requesterId,
        public bool $isAdmin,
    ) {
    }
}
