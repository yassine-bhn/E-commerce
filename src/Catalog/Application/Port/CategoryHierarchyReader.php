<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * DIP-003 — the whole taxonomy's parent map, the input of the pure CategoryHierarchy rule.
 *
 * @return array<string, string|null>
 */
interface CategoryHierarchyReader
{
    /**
     * @return array<string, string|null>
     */
    public function parentMap(): array;
}
