<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Query\ListAdminProducts;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Shared\Application\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Dashboard read of every catalog product, whatever its approval or publication state.
 *
 * DBAL-only (QRY-003), read-only (QRY-001), returns view DTOs (QRY-002) and authorizes inside the
 * SQL itself (QRY-004 / SEC-002): the requester must be a non-banned back-office role in the
 * database, even if the flag carried by the query were forged. It deliberately bypasses
 * {@see CatalogVisibility}: that predicate is what the PUBLIC surface applies (R33).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class AdminProductFinder
{
    /** Roles allowed to read the product list — mirrors the /^/api/v1/admin → ROLE_ADMIN rule. */
    private const ADMIN_ROLES = "'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'";

    private const REQUESTER_GUARD = 'EXISTS (
            SELECT 1 FROM customer_profile requester_account
            WHERE requester_account.id = :requester
              AND requester_account.role IN ('.self::ADMIN_ROLES.')
              AND requester_account.status != \'banned\'
        )';

    private const string SELECT = 'SELECT id, slug, name, name AS label, brand, ref, category_slug, subcategory,
        price_minor, old_price_minor, currency, stock, stock_quantity, material, dims, image_path, additional_images, rating, reviews,
        is_new, is_best, headrest, description, variants,
        approval_status, published, submitted_by, submitted_at, reviewed_by, reviewed_at, rejection_reason
        FROM storefront_product_view';

    /** Sort whitelist: deterministic ORDER BY, always ending on the slug. */
    private const array ORDER_BY = [
        'name' => 'name ASC, slug ASC',
        'priceAsc' => 'price_minor ASC, slug ASC',
        'priceDesc' => 'price_minor DESC, slug ASC',
        'stockAsc' => 'stock_quantity ASC NULLS LAST, name ASC, slug ASC',
        'recent' => 'submitted_at DESC NULLS LAST, name ASC, slug ASC',
        'approval' => "CASE approval_status WHEN 'PENDING_APPROVAL' THEN 0 WHEN 'REJECTED' THEN 1 ELSE 2 END, name ASC, slug ASC",
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListAdminProducts $query): Page
    {
        if (!$query->requesterIsAdmin) {
            throw new AccessDeniedException('Reading every product requires a back-office role.');
        }

        $conditions = [self::REQUESTER_GUARD];
        $params = ['requester' => $query->requesterId];

        if (null !== $query->q) {
            $conditions[] = "(name ILIKE :q ESCAPE '\\' OR slug ILIKE :q ESCAPE '\\' OR ref ILIKE :q ESCAPE '\\' OR brand ILIKE :q ESCAPE '\\')";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        if (null !== $query->category) {
            $conditions[] = 'category_slug = :category';
            $params['category'] = $query->category;
        }

        if (null !== $query->subcategory) {
            $conditions[] = 'subcategory = :sub';
            $params['sub'] = $query->subcategory;
        }

        if (null !== $query->brand) {
            $conditions[] = 'brand = :brand';
            $params['brand'] = $query->brand;
        }

        if (null !== $query->stock) {
            $conditions[] = 'stock = :stock';
            $params['stock'] = $query->stock;
        }

        if (null !== $query->approval) {
            $status = self::approvalFromSlug($query->approval);
            if (null === $status) {
                // Unknown value: answer an empty page rather than silently dropping the filter.
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = 'approval_status = :approval';
                $params['approval'] = $status->value;
            }
        }

        if (null !== $query->published) {
            $conditions[] = $query->published ? 'published = true' : 'published = false';
        }

        $where = ' WHERE '.implode(' AND ', $conditions);

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM storefront_product_view'.$where, $params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            self::SELECT.$where
            .' ORDER BY '.self::ORDER_BY[$query->effectiveSort()]
            .' LIMIT :limit OFFSET :offset',
            $params + ['limit' => $query->page->effectiveSize(), 'offset' => $query->page->effectiveOffset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return new Page(
            array_map(
                static fn (array $row): AdminProductListItem => new AdminProductListItem(
                    StorefrontProductView::fromRow($row),
                    CatalogSubmissionView::fromRow(CatalogSubmissionKind::Product, $row),
                ),
                $rows,
            ),
            $query->page->effectivePage(),
            $query->page->effectiveSize(),
            $total,
        );
    }

    private static function approvalFromSlug(string $slug): ?ApprovalStatus
    {
        return match (strtolower($slug)) {
            'pending', 'pending_approval' => ApprovalStatus::PendingApproval,
            'approved' => ApprovalStatus::Approved,
            'rejected' => ApprovalStatus::Rejected,
            default => null,
        };
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
