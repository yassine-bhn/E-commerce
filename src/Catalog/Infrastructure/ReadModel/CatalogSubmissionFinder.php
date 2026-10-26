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
 * The requester is part of the SQL predicate, not a post-filter: the back office matches the
 * whole review queue, while anybody outside it only matches rows they submitted. The page total
 * and the page rows share that predicate, so they cannot disagree.
 *
 * The record is read under an alias because both account columns join `customer_profile`: the
 * author and the reviewer are resolved to a name and an email in the same statement, so the queue
 * never has to render a uuid, and the count cannot drift from the page it counts.
 *
 * The account id is cast to text rather than the submitter to uuid: `submitted_by` and
 * `reviewed_by` are VARCHAR(64) that may hold an id this table no longer knows — a legacy or
 * anonymized account — and such a row must answer with a null author name, not with an error.
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

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                '%s%s ORDER BY %s.slug ASC LIMIT :limit OFFSET :offset',
                $this->projection($query->kind),
                $where,
                $this->alias($query->kind),
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

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s %s%s', $query->kind->table(), $this->alias($query->kind), $where),
            $params,
            $types,
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

    public function one(CatalogSubmissionKind $kind, string $slug, string $requesterId, bool $isAdmin): ?CatalogSubmissionView
    {
        $alias = $this->alias($kind);
        $conditions = [sprintf('%s.slug = :slug', $alias)];
        $params = ['slug' => $slug];
        if (!$isAdmin) {
            $conditions[] = sprintf('%s.submitted_by = :requester', $alias);
            $params['requester'] = $requesterId;
        }

        $row = $this->connection->fetchAssociative(
            sprintf('%s WHERE %s', $this->projection($kind), implode(' AND ', $conditions)),
            $params,
        );

        return false === $row ? null : CatalogSubmissionView::fromRow($kind, $row);
    }

    private function alias(CatalogSubmissionKind $kind): string
    {
        return CatalogSubmissionKind::Product === $kind ? 'product' : 'category';
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType>}
     */
    private function conditions(ListCatalogSubmissions $query): array
    {
        $alias = $this->alias($query->kind);
        $conditions = [];
        $params = [];
        $types = [];

        if (!$query->isAdmin) {
            $conditions[] = sprintf('%s.submitted_by = :requester', $alias);
            $params['requester'] = $query->requesterId;
        }

        if (null !== $query->approvalStatus) {
            $conditions[] = sprintf('%s.approval_status = :status', $alias);
            $params['status'] = $query->approvalStatus->value;
        }

        $term = null === $query->q ? '' : trim($query->q);
        if ('' !== $term) {
            $conditions[] = match ($query->kind) {
                CatalogSubmissionKind::Product => sprintf("(%s.slug ILIKE :q ESCAPE '\\' OR %s.name ILIKE :q ESCAPE '\\')", $alias, $alias),
                CatalogSubmissionKind::Category => sprintf("(%s.slug ILIKE :q ESCAPE '\\' OR %s.label ILIKE :q ESCAPE '\\')", $alias, $alias),
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
        $alias = $this->alias($kind);
        $approval = 'approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at, rejection_reason';
        $columns = implode(', ', array_map(
            static fn (string $column): string => $alias.'.'.$column,
            explode(', ', $approval),
        ));

        return match ($kind) {
            CatalogSubmissionKind::Product => sprintf(
                'SELECT product.slug, product.name AS label, product.category_slug, product.published,
                        product.image_path AS image, product.additional_images,
                        %s,
                        author.name AS author_name, author.email AS author_email,
                        reviewer.name AS reviewer_name, reviewer.email AS reviewer_email
                 FROM storefront_product_view product
                 LEFT JOIN customer_profile author ON author.id::text = product.submitted_by
                 LEFT JOIN customer_profile reviewer ON reviewer.id::text = product.reviewed_by',
                $columns,
            ),
            CatalogSubmissionKind::Category => sprintf(
                'SELECT category.slug, category.label, NULL::text AS category_slug, NULL::boolean AS published,
                        NULL::text AS image, NULL::json AS additional_images,
                        %s,
                        author.name AS author_name, author.email AS author_email,
                        reviewer.name AS reviewer_name, reviewer.email AS reviewer_email
                 FROM storefront_category category
                 LEFT JOIN customer_profile author ON author.id::text = category.submitted_by
                 LEFT JOIN customer_profile reviewer ON reviewer.id::text = category.reviewed_by',
                $columns,
            ),
        };
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
