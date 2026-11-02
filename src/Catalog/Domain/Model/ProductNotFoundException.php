<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

final class ProductNotFoundException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Product not found: %s', $slug));
    }
}
