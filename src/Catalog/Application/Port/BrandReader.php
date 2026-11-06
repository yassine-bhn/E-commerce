<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * solid Task 4.9 — reads the brand table for the command guards: existence (case-insensitive)
 * and the product count that forbids deletion.
 */
interface BrandReader
{
    public function exists(string $name): bool;

    public function countProducts(string $name): int;
}
