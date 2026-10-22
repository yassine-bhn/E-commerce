<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\ListAdminBrands;
use App\Shared\Application\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R11 — DBAL-only brand list (QRY-001/QRY-003): a filtered `COUNT` plus one bounded
 * `LIMIT/OFFSET` read sharing the same predicate, and the per-brand product aggregate
 * computed in SQL rather than in PHP (PRF-001).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class AdminBrandFinder
{
    /** Sort whitelist: deterministic ORDER BY, always ending on the unique name. */
    private const array ORDER_BY = [
        'name' => 'b.name ASC',
        'productCount' => 'product_count DESC, b.name ASC',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListAdminBrands $query): Page
    {
        $conditions = [];
        $params = [];

        if (null !== $query->q) {
            $conditions[] = "b.name ILIKE :q ESCAPE '\\'";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        $where = [] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM storefront_brand b '.$where,
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.name, COUNT(p.id) AS product_count
             FROM storefront_brand b
             LEFT JOIN storefront_product_view p ON p.brand = b.name
             '.$where.'
             GROUP BY b.name
             ORDER BY '.self::ORDER_BY[$query->effectiveSort()].'
             LIMIT :limit OFFSET :offset',
            $params + [
                'limit' => $query->page->effectiveSize(),
                'offset' => $query->page->effectiveOffset(),
            ],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return new Page(
            array_map(static fn (array $row): array => [
                'name' => (string) $row['name'],
                'productCount' => (int) $row['product_count'],
            ], $rows),
            $query->page->effectivePage(),
            $query->page->effectiveSize(),
            $total,
        );
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
