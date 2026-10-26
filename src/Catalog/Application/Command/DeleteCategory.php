<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Delete a category row (spec R4). The handler refuses it as soon as the category still
 * owns a sub-level node, a legacy child row or a product.
 *
 * spec-run-8 R20/R42 — deletion is a back-office decision; the actor travels with the command
 * so the deletion is audited with the person who took it.
 */
final readonly class DeleteCategory
{
    public function __construct(
        public string $slug,
        public ?string $actorId = null,
    ) {
    }
}
