<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Hide a category on the storefront (spec R5). The row, its sub-level tree and its
 * products all stay exactly where they are; only the visibility flag changes.
 *
 * spec-run-8 R35 — hiding is a write to the row, so it carries the same actor and ownership
 * rule as any other category mutation.
 */
final readonly class HideCategory
{
    public function __construct(
        public string $slug,
        public ?string $actorId = null,
        public bool $actorIsAdmin = false,
    ) {
    }
}
