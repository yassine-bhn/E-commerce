<?php

declare(strict_types=1);

namespace App\Promotions\Infrastructure\ReadModel;

use App\Promotions\Application\Query\GetPromoCode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.10 — the admin read of one promo code row, so the update route can answer with
 * the merged values the store wrote.
 *
 * @phpstan-type PromoCodeView array{code: string, discountBasisPoints: int, active: bool}
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetPromoCodeHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return PromoCodeView|null
     */
    public function __invoke(GetPromoCode $query): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT code, discount_basis_points, active FROM storefront_promo_code WHERE code = :code',
            ['code' => strtoupper($query->code)],
        );

        if (false === $row) {
            return null;
        }

        return [
            'code' => (string) $row['code'],
            'discountBasisPoints' => (int) $row['discount_basis_points'],
            'active' => (bool) $row['active'],
        ];
    }
}
