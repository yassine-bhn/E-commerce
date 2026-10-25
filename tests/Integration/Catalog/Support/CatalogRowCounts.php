<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Support;

use Doctrine\DBAL\Connection;

/**
 * Row counts of the catalogue tables, captured before and after an operation to prove that the
 * operation changed the schema it was aimed at and nothing else.
 *
 * A rollback test needs two independent witnesses: the probe database lost its columns while
 * keeping its rows, and the test database the suite runs on kept both.
 */
final class CatalogRowCounts
{
    /**
     * @return array{products: int, categories: int}
     */
    public static function catalogue(Connection $connection): array
    {
        return [
            'products' => self::count($connection, 'storefront_product_view'),
            'categories' => self::count($connection, 'storefront_category'),
        ];
    }

    public static function audit(Connection $connection): int
    {
        return self::count($connection, 'catalog_audit_log');
    }

    private static function count(Connection $connection, string $table): int
    {
        $count = $connection->fetchOne(sprintf('SELECT count(*) FROM %s', $connection->quoteSingleIdentifier($table)));

        if (!is_int($count) && !(is_string($count) && is_numeric($count))) {
            throw new \LogicException(sprintf('count(*) on %s did not return a number.', $table));
        }

        return (int) $count;
    }
}
