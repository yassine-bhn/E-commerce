<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Event;

final readonly class ProductRegistered
{
    public function __construct(
        public string $eventId,
        public string $productId,
        public string $name,
        public int $priceMinor,
        public string $currency,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
