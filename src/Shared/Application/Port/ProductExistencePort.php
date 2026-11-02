<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * DIP-003 — answers whether a storefront product exists, so Application code never reaches
 * into a read repository.
 */
interface ProductExistencePort
{
    public function exists(string $slug): bool;
}
