<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class InvalidProductTaxonomyException extends \DomainException
{
    public static function unknownCategory(string $category): self
    {
        return new self(sprintf('Unknown category "%s".', $category));
    }

    public static function unknownSubcategory(string $sub, string $category): self
    {
        return new self(sprintf('Unknown subcategory "%s" for category "%s".', $sub, $category));
    }
}
