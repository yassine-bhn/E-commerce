<?php

declare(strict_types=1);

namespace App\Shared\Domain\Storefront;

/**
 * solid Task 4.12 — a storefront product the caller names does not exist (or is not publicly
 * readable). Lives in Shared\Domain because more than one context names storefront products
 * (Catalog's admin writes, Identity's wishlist add).
 */
final class ProductNotFoundException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Product not found: %s', $slug));
    }
}
