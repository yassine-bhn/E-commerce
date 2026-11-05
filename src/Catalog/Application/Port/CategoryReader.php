<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Category;

/**
 * DIP-003 — reads one category row as the domain model, plus the sub-level labels its products
 * still point at (ECO-006).
 */
interface CategoryReader
{
    public function find(string $slug): ?Category;

    /** @return list<string> */
    public function usedSubLevels(string $slug): array;
}
