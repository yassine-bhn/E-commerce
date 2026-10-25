<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

/**
 * The two record families the approval workflow covers. Both share one lifecycle and one audit
 * trail, so the kind travels with every command instead of being duplicated per aggregate.
 */
enum CatalogSubmissionKind: string
{
    case Product = 'products';
    case Category = 'categories';

    public static function fromRoute(string $value): self
    {
        return self::tryFrom($value) ?? throw new \InvalidArgumentException(sprintf('Unknown catalog submission kind "%s".', $value));
    }

    public function table(): string
    {
        return match ($this) {
            self::Product => 'storefront_product_view',
            self::Category => 'storefront_category',
        };
    }

    /** The `target_type` written to the audit trail. */
    public function auditTarget(): string
    {
        return match ($this) {
            self::Product => 'product',
            self::Category => 'category',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Category => 'Category',
        };
    }
}
