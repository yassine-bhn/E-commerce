<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * spec-run-19 R6 — remove the one image of a brand. Idempotent: removing when no image is set
 * succeeds the same way.
 */
final readonly class RemoveBrandImage
{
    public function __construct(
        public string $name,
        public string $actorId,
        public bool $actorIsSuperAdmin,
    ) {
    }
}
