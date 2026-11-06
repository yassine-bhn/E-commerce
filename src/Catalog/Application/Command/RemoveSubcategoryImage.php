<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Clear the picture of one sub-category node, addressed by its label path. Idempotent: the
 * node answering the path must exist, but clearing a node without a picture succeeds the same.
 */
final class RemoveSubcategoryImage
{
    /**
     * @param list<string> $labelPath
     */
    public function __construct(
        public readonly string $slug,
        public readonly array $labelPath,
        public readonly ?string $actorId,
        public readonly bool $actorIsSuperAdmin,
    ) {
    }
}
