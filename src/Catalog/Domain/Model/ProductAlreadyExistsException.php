<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class ProductAlreadyExistsException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('A product with this slug already exists: %s', $slug));
    }
}
