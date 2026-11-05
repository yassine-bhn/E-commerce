<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * DIP-003 — the single visibility flag (spec R5): hiding is always reversible.
 */
interface CategoryVisibilityWriter
{
    public function setHidden(string $slug, bool $hidden): void;
}
