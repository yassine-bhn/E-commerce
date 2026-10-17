<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

final readonly class QuoteCart
{
    /**
     * @param list<array{slug: string, variant?: string|null, quantity: int}> $lines
     */
    public function __construct(
        public array $lines,
        public ?string $promoCode,
        public string $deliveryMethod,
    ) {
    }
}
