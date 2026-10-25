<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Create a top-level storefront category (spec R1). Its sub-categories are nodes of the
 * `subcategories` tree of this row (ADR-0006), never child rows.
 *
 * spec-run-8 R2/R10 — the creator travels with the command because the row is stored as a
 * `PENDING_APPROVAL` submission owned by them; a null actor is a system context (CLI, tests).
 */
final readonly class CreateCategory
{
    /**
     * @param list<array<string, mixed>> $subcategories
     */
    public function __construct(
        public string $slug,
        public string $label,
        public array $subcategories = [],
        public bool $quoteEligible = false,
        public ?string $actorId = null,
    ) {
    }
}
