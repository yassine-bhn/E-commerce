<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Make a hidden category visible on the storefront again (spec R5): the exact inverse
 * of {@see HideCategory}, never anything else.
 */
final readonly class ShowCategory
{
    public function __construct(
        public string $slug,
        public ?string $actorId = null,
        public bool $actorIsSuperAdmin = false,
    ) {
    }
}
