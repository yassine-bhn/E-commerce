<?php

declare(strict_types=1);

namespace App\Promotions\Infrastructure\Persistence;

use App\Promotions\Application\Port\PromoCodeReader;
use App\Promotions\Application\Port\PromoCodeStore;
use App\Promotions\Domain\Model\PromoCodeNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * solid Task 4.10 — the promo-code adapter: the CRUD the admin controller used to run inline.
 * A missing code answers PromoCodeNotFoundException (the controller maps 404).
 */
final class DbalPromoCodeStore implements PromoCodeReader, PromoCodeStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function exists(string $code): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM storefront_promo_code WHERE code = :code',
            ['code' => $code],
        );
    }

    public function find(string $code): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT code, discount_basis_points, active FROM storefront_promo_code WHERE code = :code',
            ['code' => $code],
        );

        if (false === $row) {
            return null;
        }

        return [
            'code' => (string) $row['code'],
            'discount_basis_points' => (int) $row['discount_basis_points'],
            'active' => (bool) $row['active'],
        ];
    }

    public function create(string $code, int $discountBasisPoints, bool $active): void
    {
        $this->connection->executeStatement(
            'INSERT INTO storefront_promo_code (code, discount_basis_points, active) VALUES (:code, :bp, :active)',
            ['code' => $code, 'bp' => $discountBasisPoints, 'active' => $active],
            ['bp' => ParameterType::INTEGER, 'active' => ParameterType::BOOLEAN],
        );
    }

    public function update(string $code, int $discountBasisPoints, bool $active): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_promo_code SET discount_basis_points = :bp, active = :active WHERE code = :code',
            ['bp' => $discountBasisPoints, 'active' => $active, 'code' => $code],
            ['bp' => ParameterType::INTEGER, 'active' => ParameterType::BOOLEAN],
        );
    }

    public function delete(string $code): void
    {
        $deleted = $this->connection->executeStatement('DELETE FROM storefront_promo_code WHERE code = :code', ['code' => $code]);
        if (0 === $deleted) {
            throw PromoCodeNotFoundException::forCode($code);
        }
    }
}
