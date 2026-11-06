<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

final readonly class StorefrontProductView
{
    /**
     * @param list<array{label: string, price: int}> $variants
     * @param list<string>                           $additionalImages pictures after the primary one
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $brand,
        public string $ref,
        public string $categorySlug,
        public string $subcategory,
        public int $priceMinor,
        public ?int $oldPriceMinor,
        public string $currency,
        public string $stock,
        public string $material,
        public string $dims,
        public string $imagePath,
        public float $rating,
        public int $reviews,
        public bool $isNew,
        public bool $isBest,
        public bool $headrest,
        public string $description,
        public array $variants,
        public array $additionalImages = [],
        public ?int $stockQuantity = null,
        public ?string $supplierReference = null,
        public ?float $vatRate = null,
        public ?int $salePriceTtc = null,
    ) {
    }

    /**
     * A3 — the `stock` label is the storefront display. The numeric quantity stays internal
     * (oversell invariant, quote guard) and is deliberately absent from the public payload.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'brand' => $this->brand,
            'ref' => $this->ref,
            'category' => $this->categorySlug,
            'sub' => $this->subcategory,
            'price' => $this->priceMinor,
            'oldPrice' => $this->oldPriceMinor,
            'currency' => $this->currency,
            'variants' => $this->variants,
            'headrest' => $this->headrest,
            'stock' => $this->stock,
            'material' => $this->material,
            'dims' => $this->dims,
            'image' => $this->imagePath,
            'additionalImages' => $this->additionalImages,
            'rating' => $this->rating,
            'reviews' => $this->reviews,
            'isNew' => $this->isNew,
            'best' => $this->isBest,
            'description' => $this->description,
        ];
    }

    /**
     * Admin projection: the storefront fields plus the numeric quantity operators edit (O-01),
     * and the supplier's own reference, which the customer-facing payload never carries.
     *
     * @return array<string, mixed>
     */
    public function toAdminApi(): array
    {
        $payload = $this->toApi();
        $payload['stockQuantity'] = $this->stockQuantity;
        $payload['supplierReference'] = $this->supplierReference;
        $payload['vatRate'] = $this->vatRate;
        $payload['salePriceTtc'] = $this->salePriceTtc;

        return $payload;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        /** @var list<array{label: string, price: int}> $variants */
        $variants = json_decode((string) $row['variants'], true, 512, JSON_THROW_ON_ERROR);

        $additionalImages = [];
        if (isset($row['additional_images']) && is_string($row['additional_images'])) {
            $decoded = json_decode($row['additional_images'], true);
            if (is_array($decoded)) {
                $additionalImages = array_values(array_map(strval(...), $decoded));
            }
        }

        return new self(
            (string) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['brand'],
            (string) $row['ref'],
            (string) $row['category_slug'],
            (string) $row['subcategory'],
            (int) $row['price_minor'],
            isset($row['old_price_minor']) ? (int) $row['old_price_minor'] : null,
            (string) $row['currency'],
            (string) $row['stock'],
            (string) $row['material'],
            (string) $row['dims'],
            (string) $row['image_path'],
            (float) $row['rating'],
            (int) $row['reviews'],
            (bool) $row['is_new'],
            (bool) $row['is_best'],
            (bool) $row['headrest'],
            (string) $row['description'],
            $variants,
            $additionalImages,
            isset($row['stock_quantity']) ? (int) $row['stock_quantity'] : null,
            isset($row['supplier_reference']) ? (string) $row['supplier_reference'] : null,
            isset($row['vat_rate']) ? (float) $row['vat_rate'] : null,
            isset($row['sale_price_ttc_minor']) ? (int) $row['sale_price_ttc_minor'] : null,
        );
    }
}
