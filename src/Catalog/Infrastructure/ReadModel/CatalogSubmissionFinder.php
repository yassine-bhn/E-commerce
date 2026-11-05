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

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*)%s%s', $this->from($query->kind), $where),
            $params,
            $types,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                '%s%s ORDER BY s.slug ASC LIMIT :limit OFFSET :offset',
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
        $conditions = ['s.slug = :slug'];
        $params = ['slug' => $slug];
        if (!$isSuperAdmin) {
            $conditions[] = 's.submitted_by = :requester';
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
            $conditions[] = 's.submitted_by = :requester';
            $params['requester'] = $query->requesterId;
        }

        if (null !== $query->approvalStatus) {
            $conditions[] = 's.approval_status = :status';
            $params['status'] = $query->approvalStatus->value;
        }

        $term = null === $query->q ? '' : trim($query->q);
        if ('' !== $term) {
            $conditions[] = match ($query->kind) {
                CatalogSubmissionKind::Product => "(s.slug ILIKE :q ESCAPE '\\' OR c.name ILIKE :q ESCAPE '\\')",
                CatalogSubmissionKind::Category => "(s.slug ILIKE :q ESCAPE '\\' OR c.label ILIKE :q ESCAPE '\\')",
                CatalogSubmissionKind::Brand => "(s.slug ILIKE :q ESCAPE '\\' OR c.name ILIKE :q ESCAPE '\\')",
            };
            $params['q'] = '%'.self::escapeLike($term).'%';
        }

        return [
            [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions),
            $params,
            $types,
        ];
    }

    /**
     * The approval state lives in `catalog_submission`; the content columns are joined from the
     * view only for `label`, `category_slug` and `published`. Every predicate is qualified with
     * `s.` so a stale content projection cannot answer a workflow filter.
     */
    private function from(CatalogSubmissionKind $kind): string
    {
        return match ($kind) {
            CatalogSubmissionKind::Product => ' FROM catalog_submission s
                JOIN storefront_product_view c ON c.slug = s.slug',
            CatalogSubmissionKind::Category => ' FROM catalog_submission s
                JOIN storefront_category c ON c.slug = s.slug',
            CatalogSubmissionKind::Brand => ' FROM catalog_submission s
                JOIN storefront_brand c ON c.name = s.slug',
        };
    }

    private function projection(CatalogSubmissionKind $kind): string
    {
        return match ($kind) {
            CatalogSubmissionKind::Product => 'SELECT s.slug, c.name AS label, c.category_slug, s.approval_status, s.submitted_by,
                    s.submitted_at, s.reviewed_by, s.reviewed_at, s.rejection_reason, c.published'
                .$this->from($kind),
            CatalogSubmissionKind::Category => 'SELECT s.slug, c.label, NULL::text AS category_slug, s.approval_status, s.submitted_by,
                    s.submitted_at, s.reviewed_by, s.reviewed_at, s.rejection_reason, NULL::boolean AS published'
                .$this->from($kind),
            CatalogSubmissionKind::Brand => 'SELECT s.slug, c.name AS label, NULL::text AS category_slug, s.approval_status, s.submitted_by,
                    s.submitted_at, s.reviewed_by, s.reviewed_at, s.rejection_reason, NULL::boolean AS published'
                .$this->from($kind),
        };
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
