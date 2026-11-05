<?php

declare(strict_types=1);

namespace App\Cart\Application\Dto;

use App\Shared\Domain\Money;

final readonly class CartQuote
{
    /**
     * @param list<array{slug: string, variant: ?string, unitPrice: int, quantity: int}> $lines
     */
    public function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $delivery,
        public Money $total,
        public int $itemCount,
        public array $lines = [],
        public ?string $promoCode = null,
        public string $deliveryMethod = 'home',
    ) {
    }
}
