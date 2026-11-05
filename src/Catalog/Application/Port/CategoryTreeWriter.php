<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Category;

/**
 * DIP-003 — the content writes on a category row: the whole editable tree, or one added
 * sub-level. Visibility is deliberately not part of these statements — hiding is its own command.
 */
interface CategoryTreeWriter
{
    public function replaceContent(Category $category): void;

    public function addSubLevel(Category $category): void;
}
