<?php

declare(strict_types=1);

namespace App\Promotions\Application\Command;

/**
 * solid Task 4.10 — the merge update: null fields keep their stored value, so the handler reads
 * the current row through PromoCodeReader and writes the merged result.
 */
final readonly class UpdatePromoCode
{
    public function __construct(
        public string $code,
        public ?int $discountBasisPoints,
        public ?bool $active,
    ) {
    }
}
