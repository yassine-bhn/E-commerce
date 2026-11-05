<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Support;

use App\Shared\Application\Dto\StorefrontProductSnapshot;
use App\Shared\Application\Port\StorefrontCatalogPort;

final class FakeStorefrontCatalog implements StorefrontCatalogPort
{
    public ?StorefrontProductSnapshot $product = null;

    public function getBySlug(string $slug): ?StorefrontProductSnapshot
    {
        return $this->product;
    }
}
