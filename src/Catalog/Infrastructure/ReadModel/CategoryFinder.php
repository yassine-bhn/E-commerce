<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\ListCategories;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * DBAL-only category list (spec R8, QRY-001/QRY-003): read DTOs out, nothing written back.
 *
 * The requester travels with the query and is part of the SQL predicate, so a hidden
 * category can only be read by an identified admin — the storefront-facing shape of the
 * same table stays hidden from everybody else (QRY-004).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class CategoryFinder
{
    private const SQL = "SELECT c.slug, c.label, c.subcategories, c.quote_eligible, c.hidden, c.sort_order, c.parent_slug,
            (SELECT COUNT(*) FROM storefront_product_view p WHERE p.category_slug = c.slug) AS product_count
        FROM storefront_category c
        WHERE (c.hidden = false
            OR (:adminScope = true AND :requester <> ''))
        ORDER BY c.sort_order ASC, c.slug ASC";

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<CategoryView>
     */
    public function __invoke(ListCategories $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            self::SQL,
            [
                'adminScope' => $query->isAdmin,
                'requester' => trim($query->requesterId),
            ],
            [
                'adminScope' => ParameterType::BOOLEAN,
            ],
        );

        return array_map(static fn (array $row): CategoryView => CategoryView::fromRow($row), $rows);
    }
}
