<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class CategoryHierarchyCycleException extends \DomainException
{
    public static function selfParent(string $slug): self
    {
        return new self(sprintf('Category "%s" cannot be set as its own parent.', $slug));
    }

    public static function descendantCycle(string $categorySlug, string $targetParentSlug): self
    {
        return new self(sprintf(
            'Circular category hierarchy detected: "%s" is a descendant of "%s".',
            $targetParentSlug,
            $categorySlug,
        ));
    }
}
