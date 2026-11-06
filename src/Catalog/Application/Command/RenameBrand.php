<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class RenameBrand
{
    public function __construct(
        public string $old,
        public string $new,
        public string $actorId,
        public bool $actorIsSuperAdmin,
    ) {
    }
}
