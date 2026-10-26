<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Add one sub-category node to the `subcategories` tree of an existing category (spec R2).
 * A null `$parentLabel` puts the node at the top level of that tree.
 *
 * spec-run-8 R35 — the actor travels with the command: adding a node is a content write, so it
 * obeys the same ownership rule as replacing the whole tree.
 */
final readonly class AddCategorySubLevel
{
    public function __construct(
        public string $slug,
        public string $label,
        public ?string $parentLabel = null,
        public ?string $actorId = null,
        public bool $actorIsAdmin = false,
    ) {
    }
}
