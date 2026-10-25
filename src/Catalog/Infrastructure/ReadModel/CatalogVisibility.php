<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

/**
 * spec-run-8 R29–R32 — the one SQL definition of "the storefront may see this product".
 *
 * A product is public only when it is approved, explicitly published, and attached to a category
 * that is itself approved and visible. The browse/detail reads, the search index and the catalogue
 * port all append this same fragment, so they cannot drift apart.
 */
final class CatalogVisibility
{
    public const string PRODUCT_PUBLIC = " approval_status = 'APPROVED' AND published = true
        AND EXISTS (
            SELECT 1 FROM storefront_category c
            WHERE c.slug = storefront_product_view.category_slug
              AND c.approval_status = 'APPROVED'
              AND c.hidden = false
        )";
}
