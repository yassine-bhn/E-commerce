<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

/**
 * One row of the dashboard product list: the admin projection of the product (storefront fields
 * plus `stockQuantity`) and its approval block, exactly as `GET /admin/catalog/products/{slug}`
 * returns them, so the list and the detail never disagree.
 */
final readonly class AdminProductListItem implements \JsonSerializable
{
    public function __construct(
        public StorefrontProductView $product,
        public CatalogSubmissionView $approval,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->product->toAdminApi() + ['approval' => $this->approval->toApi()];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
