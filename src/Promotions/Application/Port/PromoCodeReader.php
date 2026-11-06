<?php

declare(strict_types=1);

namespace App\Promotions\Application\Port;

/**
 * solid Task 4.10 — reads one promo code row for the guards and the merge update.
 *
 * @phpstan-type PromoCodeRow array{code: string, discount_basis_points: int, active: bool}
 */
interface PromoCodeReader
{
    public function exists(string $code): bool;

    /** @return PromoCodeRow|null */
    public function find(string $code): ?array;
}
