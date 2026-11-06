<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class DeleteBrand
{
    public function __construct(
        public string $name,
        public string $actorId,
    ) {
    }
}
