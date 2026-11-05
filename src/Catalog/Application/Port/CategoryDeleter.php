<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * DIP-003 — the category row delete (refusals happen before anything is written).
 */
interface CategoryDeleter
{
    public function delete(string $slug): void;
}
