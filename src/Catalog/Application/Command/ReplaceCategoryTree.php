<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Replace the editable content of a category: its label, its quote-eligibility flag and
 * its whole sub-category tree (spec R3). The slug and the storefront visibility are not
 * part of this command.
 *
 * spec-run-8 R7/R8/R25/R35 — the actor travels with the command because the handler decides
 * whether they may write this row and whether the write reopens an approved record.
 */
final readonly class ReplaceCategoryTree
{
    /**
     * @param array<array-key, mixed> $subcategories
     */
    public function __construct(
        public string $slug,
        public string $label,
        public array $subcategories = [],
        public bool $quoteEligible = false,
        public ?string $actorId = null,
        public bool $actorIsAdmin = false,
    ) {
    }
}
