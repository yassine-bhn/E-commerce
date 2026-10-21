<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class CategorySublevelException extends \DomainException
{
    public static function notAnArray(): self
    {
        return new self('The subcategories must be a list of {label, children?} nodes.');
    }

    public static function nodeWithoutLabel(): self
    {
        return new self('Every subcategory node needs a label.');
    }

    public static function tooDeep(int $depth): self
    {
        return new self(sprintf(
            'Subcategories may not be nested deeper than %d levels, %d given.',
            CategorySubtree::MAX_DEPTH,
            $depth,
        ));
    }

    public static function duplicatedSibling(string $label): self
    {
        return new self(sprintf('The subcategory "%s" already exists at this level.', $label));
    }

    public static function unknownParent(string $label): self
    {
        return new self(sprintf('Unknown parent subcategory "%s".', $label));
    }
}
