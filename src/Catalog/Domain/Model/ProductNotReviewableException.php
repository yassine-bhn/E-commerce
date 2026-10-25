<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * spec-run-12 R17 — a review names a product the storefront does not serve.
 *
 * Reviews are public content about a product, so they are bound to the same visibility the product
 * detail is bound to: a hidden row is "not found", never "forbidden", so the refusal cannot be used
 * to probe what exists (ERR-002: the edge maps it to 404).
 */
final class ProductNotReviewableException extends \DomainException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('The product "%s" is not reviewable.', $slug));
    }
}
