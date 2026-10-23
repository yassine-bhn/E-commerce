<?php

declare(strict_types=1);

namespace App\Shared\Application\Dto;

final readonly class StorefrontProductSnapshot
{
    /**
     * @param list<array{label: string, price: int}> $variants
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public int $priceMinor,
        public string $stock,
        public array $variants,
        public ?int $stockQuantity = null,
    ) {
    }
}
