<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Category;

/**
 * DIP-003 — the category creation write: the next sort order and the visible-by-default insert.
 * A taken slug leaves as CategoryAlreadyExistsException (the adapter owns the unique mapping).
 */
interface CategoryCreator
{
    public function create(Category $category): void;
}
