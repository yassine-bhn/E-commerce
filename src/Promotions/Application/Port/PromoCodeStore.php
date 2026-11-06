<?php

declare(strict_types=1);

namespace App\Promotions\Application\Port;

/**
 * solid Task 4.10 — the promo-code writes; delete refuses a missing code.
 */
interface PromoCodeStore
{
    public function create(string $code, int $discountBasisPoints, bool $active): void;

    public function update(string $code, int $discountBasisPoints, bool $active): void;

    public function delete(string $code): void;
}
