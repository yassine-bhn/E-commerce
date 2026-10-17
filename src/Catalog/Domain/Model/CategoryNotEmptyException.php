<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class CategoryNotEmptyException extends \DomainException
{
    public static function forCategory(string $slug, int $subcategoriesCount, int $productsCount): self
    {
        return new self(sprintf(
            'Cannot delete category "%s": it contains %d subcategories and %d products.',
            $slug,
            $subcategoriesCount,
            $productsCount,
        ));
    }
}
