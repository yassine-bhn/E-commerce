<?php

declare(strict_types=1);

namespace App\Cart\Application\Service;

use App\Shared\Application\Service\StorefrontQuoteService;

final class CartLinePricer
{
    public function __construct(private StorefrontQuoteService $quotes)
    {
    }

    /**
     * @return array{unitPriceMinor: int, currency: string}
     */
    public function price(string $slug, ?string $variant, int $quantity): array
    {
        $result = $this->quotes->quote(
            [['slug' => $slug, 'variant' => $variant, 'quantity' => $quantity]],
            null,
            'home',
        );
        $priced = $result['pricedLines'][0];

        return [
            'unitPriceMinor' => $priced['unitPriceMinor'],
            'currency' => $result['subtotal']->currency()->value,
        ];
    }
}
