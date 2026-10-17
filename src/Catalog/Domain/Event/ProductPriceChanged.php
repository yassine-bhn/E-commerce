<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

final readonly class ProductPriceChanged
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public int $priceMinor,
        public string $currency,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
