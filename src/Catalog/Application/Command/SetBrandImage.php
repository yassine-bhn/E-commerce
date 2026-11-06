<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * spec-run-19 R3/R5 — attach or replace the one image of a brand. The raw reference is
 * validated by the domain value object inside the handler; the access policy is the store's.
 */
final readonly class SetBrandImage
{
    public function __construct(
        public string $name,
        public string $image,
        public string $actorId,
        public bool $actorIsSuperAdmin,
    ) {
    }
}
