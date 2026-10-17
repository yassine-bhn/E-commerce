<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\Dto\StorefrontProductSnapshot;

interface StorefrontCatalogPort
{
    public function getBySlug(string $slug): ?StorefrontProductSnapshot;
}
