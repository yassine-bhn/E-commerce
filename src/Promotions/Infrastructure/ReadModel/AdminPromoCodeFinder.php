<?php

declare(strict_types=1);

namespace App\Promotions\Infrastructure\ReadModel;

use App\Promotions\Application\Query\ListAdminPromoCodes;
use App\Shared\Application\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R12 — DBAL-only promo-code list (QRY-001/QRY-003): a filtered `COUNT` plus one bounded
 * `LIMIT/OFFSET` read sharing the same predicate, in the fixed `code` order. The discount stays
 * integer basis points all the way to the edge.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class AdminPromoCodeFinder
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListAdminPromoCodes $query): Page
    {
        $conditions = [];
        $params = [];
        $types = [];

        if (null !== $query->q) {
            $conditions[] = "code ILIKE :q ESCAPE '\\'";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        if (null !== $query->active) {
            $conditions[] = 'active = :active';
            $params['active'] = $query->active;
            $types['active'] = ParameterType::BOOLEAN;
        }

        $where = [] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM storefront_promo_code '.$where,
            $params,
            $types,
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT code, discount_basis_points, active
             FROM storefront_promo_code
             '.$where.'
             ORDER BY code ASC
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
            array_map(static fn (array $row): array => [
                'code' => (string) $row['code'],
                'discountBasisPoints' => (int) $row['discount_basis_points'],
                'active' => (bool) $row['active'],
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
