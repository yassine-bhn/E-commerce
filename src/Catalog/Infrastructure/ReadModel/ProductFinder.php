<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\GetProduct;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ProductFinder
{
    private const SQL = 'SELECT id, name, price_minor, currency FROM catalog_product_view WHERE id = :id';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetProduct $query): ?ProductView
    {
        $row = $this->connection->fetchAssociative(self::SQL, ['id' => $query->productId]);
        if (false === $row) {
            return null;
        }

        return new ProductView(
            (string) $row['id'],
            (string) $row['name'],
            (int) $row['price_minor'],
            (string) $row['currency'],
        );
    }
}
