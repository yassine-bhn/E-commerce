<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\ListCategories;
use App\Shared\Application\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * DBAL-only category list (spec R8/R10, QRY-001/QRY-003): read DTOs out, nothing written back.
 *
 * One filtered `COUNT` plus one bounded `LIMIT/OFFSET` read share the same predicates, so the
 * envelope total and the returned rows can never disagree. The requester travels with the query
 * and is part of that SQL predicate, so a hidden category can only be read by an identified
 * admin — the storefront-facing shape of the same table stays hidden from everybody else (QRY-004).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class CategoryFinder
{
    /** Sort whitelist: deterministic ORDER BY, always ending on the unique slug. */
    private const array ORDER_BY = [
        'sortOrder' => 'c.sort_order ASC, c.slug ASC',
        'label' => 'c.label ASC, c.slug ASC',
        'productCount' => 'product_count DESC, c.slug ASC',
    ];

    private const string VISIBILITY_GUARD = '(c.hidden = false OR (:adminScope = true AND :requester <> \'\'))';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListCategories $query): Page
    {
        $conditions = [self::VISIBILITY_GUARD];
        $params = [
            'adminScope' => $query->isAdmin,
            'requester' => trim($query->requesterId),
        ];
        $types = ['adminScope' => ParameterType::BOOLEAN];

        if (null !== $query->q) {
            $conditions[] = "(c.slug ILIKE :q ESCAPE '\\' OR c.label ILIKE :q ESCAPE '\\')";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        if (null !== $query->hidden) {
            $conditions[] = 'c.hidden = :hidden';
            $params['hidden'] = $query->hidden;
            $types['hidden'] = ParameterType::BOOLEAN;
        }

        if (null !== $query->parent) {
            $conditions[] = 'c.parent_slug = :parent';
            $params['parent'] = $query->parent;
        }

        $where = implode(' AND ', $conditions);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM storefront_category c WHERE '.$where,
            $params,
            $types,
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.slug, c.label, c.subcategories, c.quote_eligible, c.hidden, c.sort_order, c.parent_slug,
                    (SELECT COUNT(*) FROM storefront_product_view p WHERE p.category_slug = c.slug) AS product_count
             FROM storefront_category c
             WHERE '.$where.'
             ORDER BY '.self::ORDER_BY[$query->effectiveSort()].'
             LIMIT :limit OFFSET :offset',
            $params + [
                'limit' => $query->page->effectiveSize(),
                'offset' => $query->page->effectiveOffset(),
            ],
            $types + [
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        );

        return new Page(
            array_map(static fn (array $row): CategoryView => CategoryView::fromRow($row), $rows),
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
