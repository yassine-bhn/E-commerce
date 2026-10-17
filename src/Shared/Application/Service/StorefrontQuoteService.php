<?php

declare(strict_types=1);

namespace App\Shared\Application\Service;

use App\Shared\Application\Dto\StorefrontProductSnapshot;
use App\Shared\Application\Port\StorefrontCatalogPort;
use App\Shared\Application\Port\StorefrontPricingPort;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

final class StorefrontQuoteService
{
    public function __construct(
        private StorefrontCatalogPort $catalog,
        private StorefrontPricingPort $pricing,
    ) {
    }

    /**
     * @param list<array{slug: string, variant?: string|null, quantity?: int, qty?: int}> $lines
     *
     * @return array{
     *     pricedLines: list<array{slug: string, name: string, variant: ?string, unitPriceMinor: int, quantity: int}>,
     *     subtotal: Money,
     *     discount: Money,
     *     delivery: Money,
     *     total: Money,
     *     itemCount: int
     * }
     */
    public function quote(array $lines, ?string $promoCode, string $deliveryMethod): array
    {
        $currency = Currency::TND;
        $subtotal = Money::ofMinor(0, $currency);
        $itemCount = 0;
        $pricedLines = [];

        foreach ($lines as $line) {
            $product = $this->catalog->getBySlug($line['slug']);
            if (null === $product) {
                throw new \InvalidArgumentException(sprintf('Unknown product slug: %s', $line['slug']));
            }
            if ('out' === $product->stock) {
                throw new \InvalidArgumentException(sprintf('Product out of stock: %s', $line['slug']));
            }

            $variant = $line['variant'] ?? null;
            $unitMinor = $this->resolveUnitPrice($product, $variant);
            $qty = max(1, (int) ($line['quantity'] ?? $line['qty'] ?? 1));
            $itemCount += $qty;
            $subtotal = $subtotal->add(Money::ofMinor($unitMinor, $currency)->multiply($qty));
            $pricedLines[] = [
                'slug' => $product->slug,
                'name' => $product->name,
                'variant' => $variant,
                'unitPriceMinor' => $unitMinor,
                'quantity' => $qty,
            ];
        }

        $basisPoints = $this->pricing->promoBasisPoints($promoCode);
        $discount = null === $basisPoints
            ? Money::ofMinor(0, $currency)
            : $subtotal->basisPointDiscount($basisPoints);

        $afterDiscount = $subtotal->subtract($discount);
        $deliveryMinor = $this->pricing->deliveryPolicy()->deliveryFeeMinor($afterDiscount->minorUnits(), $deliveryMethod);
        $delivery = Money::ofMinor($deliveryMinor, $currency);
        $total = $afterDiscount->add($delivery);

        return [
            'pricedLines' => $pricedLines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'delivery' => $delivery,
            'total' => $total,
            'itemCount' => $itemCount,
        ];
    }

    private function resolveUnitPrice(StorefrontProductSnapshot $product, ?string $variant): int
    {
        if (null === $variant || '' === $variant) {
            return $product->priceMinor;
        }
        foreach ($product->variants as $v) {
            if ($v['label'] === $variant) {
                return (int) $v['price'];
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown variant for %s: %s', $product->slug, $variant));
    }
}
