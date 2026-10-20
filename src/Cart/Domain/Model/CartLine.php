<?php

declare(strict_types=1);

namespace App\Cart\Domain\Model;

final class CartLine
{
    public function __construct(
        private CartLineId $id,
        private string $productSlug,
        private ?string $variant,
        private int $quantity,
        private int $unitPriceMinor,
        private string $currency,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1');
        }
    }

    public static function create(
        CartLineId $id,
        string $productSlug,
        ?string $variant,
        int $quantity,
        int $unitPriceMinor,
        string $currency,
    ): self {
        return new self($id, $productSlug, $variant, $quantity, $unitPriceMinor, $currency);
    }

    public function id(): CartLineId
    {
        return $this->id;
    }

    public function productSlug(): string
    {
        return $this->productSlug;
    }

    public function variant(): ?string
    {
        return $this->variant;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPriceMinor(): int
    {
        return $this->unitPriceMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function lineTotalMinor(): int
    {
        return $this->unitPriceMinor * $this->quantity;
    }

    public function matchesProduct(string $slug, ?string $variant): bool
    {
        return $this->productSlug === $slug && $this->normalizeVariant($this->variant) === $this->normalizeVariant($variant);
    }

    public function changeQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1');
        }
        $this->quantity = $quantity;
    }

    public function changeVariant(?string $variant, int $unitPriceMinor): void
    {
        $this->variant = $variant;
        $this->unitPriceMinor = $unitPriceMinor;
    }

    private function normalizeVariant(?string $variant): ?string
    {
        if (null === $variant || '' === trim($variant)) {
            return null;
        }

        return trim($variant);
    }
}
