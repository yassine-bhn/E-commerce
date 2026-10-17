<?php

declare(strict_types=1);

namespace App\Promotions\Infrastructure\ReadModel;

use App\Promotions\Application\Query\ValidatePromoCode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ValidatePromoCodeHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(ValidatePromoCode $query): array
    {
        $code = strtoupper(trim($query->code));
        if ('' === $code) {
            return ['valid' => false, 'code' => '', 'error' => 'Code promo requis'];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT code, discount_basis_points, active FROM storefront_promo_code WHERE code = :code',
            ['code' => $code],
        );

        if (false === $row || !(bool) $row['active']) {
            return ['valid' => false, 'code' => $code, 'error' => 'Code promo invalide ou expiré'];
        }

        $basisPoints = (int) $row['discount_basis_points'];

        return [
            'valid' => true,
            'code' => (string) $row['code'],
            'discountBasisPoints' => $basisPoints,
            'discountPercent' => round($basisPoints / 100, 1),
        ];
    }
}
