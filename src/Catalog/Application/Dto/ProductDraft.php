<?php

declare(strict_types=1);

namespace App\Catalog\Application\Dto;

/**
 * The validated product content the controller builds from the request body (solid Task 4.11):
 * the edge owns the field-level validation, the writer owns the persistence.
 *
 * @phpstan-type ProductVariant array{label: string, price: int}
 */
final readonly class ProductDraft
{
    /**
     * @param list<ProductVariant> $variants
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $brand,
        public string $ref,
        public ?string $supplierReference,
        public string $category,
        public string $sub,
        public int $priceMinor,
        public ?int $oldPriceMinor,
        public string $stock,
        public ?int $stockQuantity,
        public string $material,
        public string $dims,
        public string $image,
        public bool $isNew,
        public bool $best,
        public bool $headrest,
        public string $description,
        public array $variants,
        public ?string $vatRate,
        public ?int $salePriceTtcMinor,
    ) {
    }
}
