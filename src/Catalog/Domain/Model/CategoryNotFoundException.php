<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class CategoryNotFoundException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Category "%s" does not exist.', $slug));
    }
}
