<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\ListCatalogSubmissions;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Shared\Application\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * spec-run-8 R5/R6/R15/R16 — DBAL-only reads of the approval columns (QRY-001/QRY-003).
 *
 * The requester is part of the SQL predicate, not a post-filter: a regular Admin can only match
 * rows they submitted, while the Super Admin matches the whole review queue. The page total and
 * the page rows share that predicate, so they cannot disagree.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class CatalogSubmissionFinder
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(ListCatalogSubmissions $query): Page
    {
        [$where, $params, $types] = $this->conditions($query);

        $table = $query->kind->table();

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s%s', $table, $where),
            $params,
            $types,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                '%s%s ORDER BY slug ASC LIMIT :limit OFFSET :offset',
                $this->projection($query->kind),
                $where,
            ),
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
            array_map(
                static fn (array $row): CatalogSubmissionView => CatalogSubmissionView::fromRow($query->kind, $row),
                $rows,
            ),
            $query->page->effectivePage(),
            $query->page->effectiveSize(),
            $total,
        );
    }

    public function one(CatalogSubmissionKind $kind, string $slug, string $requesterId, bool $isSuperAdmin): ?CatalogSubmissionView
    {
        $conditions = ['slug = :slug'];
        $params = ['slug' => $slug];
        if (!$isSuperAdmin) {
            $conditions[] = 'submitted_by = :requester';
            $params['requester'] = $requesterId;
        }

        $row = $this->connection->fetchAssociative(
            sprintf('%s WHERE %s', $this->projection($kind), implode(' AND ', $conditions)),
            $params,
        );

        return false === $row ? null : CatalogSubmissionView::fromRow($kind, $row);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType>}
     */
    private function conditions(ListCatalogSubmissions $query): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if (!$query->isSuperAdmin) {
            $conditions[] = 'submitted_by = :requester';
            $params['requester'] = $query->requesterId;
        }

        if (null !== $query->approvalStatus) {
            $conditions[] = 'approval_status = :status';
            $params['status'] = $query->approvalStatus->value;
        }

        $term = null === $query->q ? '' : trim($query->q);
        if ('' !== $term) {
            $conditions[] = match ($query->kind) {
                CatalogSubmissionKind::Product => "(slug ILIKE :q ESCAPE '\\' OR name ILIKE :q ESCAPE '\\')",
                CatalogSubmissionKind::Category => "(slug ILIKE :q ESCAPE '\\' OR label ILIKE :q ESCAPE '\\')",
            };
            $params['q'] = '%'.self::escapeLike($term).'%';
        }

        return [
            [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions),
            $params,
            $types,
        ];
    }

    private function projection(CatalogSubmissionKind $kind): string
    {
        return match ($kind) {
            CatalogSubmissionKind::Product => 'SELECT slug, name AS label, category_slug, approval_status, submitted_by,
                    submitted_at, reviewed_by, reviewed_at, rejection_reason, published
                FROM storefront_product_view',
            CatalogSubmissionKind::Category => 'SELECT slug, label, NULL::text AS category_slug, approval_status, submitted_by,
                    submitted_at, reviewed_by, reviewed_at, rejection_reason, NULL::boolean AS published
                FROM storefront_category',
        };
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
