<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Application\Query\ListAdminOrders;
use App\Ordering\Domain\Model\OrderSource;
use App\Shared\Application\Pagination\Page;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Dashboard read of every order as an offset page with filters and a whitelisted sort.
 *
 * DBAL-only (QRY-003), read-only (QRY-001), returns view DTOs (QRY-002) and authorizes inside the
 * SQL itself (QRY-004 / SEC-002): the requester must be a non-banned admin in the database, even
 * if the flag carried by the query were forged. A page costs three statements whatever its size —
 * the count, the orders and one `IN` statement for every line of the page (PRF-001).
 *
 * Online orders take their contact from the customer profile; phone/WhatsApp proxy orders carry a
 * denormalized contact snapshot on the order row itself, which wins when present.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class AdminOrderFinder
{
    /** Roles allowed to read the order list — mirrors the /^/api/v1/admin → ROLE_ADMIN rule. */
    private const ADMIN_ROLES = "'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'";

    private const REQUESTER_GUARD = 'EXISTS (
            SELECT 1 FROM customer_profile requester_account
            WHERE requester_account.id = :requester
              AND requester_account.role IN ('.self::ADMIN_ROLES.')
              AND requester_account.status != \'banned\'
        )';

    private const string SQL_LINES = 'SELECT order_id, product_slug, product_name, variant_label, unit_price_minor, currency, quantity
                               FROM ordering_order_lines
                               WHERE order_id IN (:ids)
                               ORDER BY id ASC';

    /** Sort whitelist: deterministic ORDER BY, always ending on the primary key. */
    private const array ORDER_BY = [
        'newest' => 'o.placed_at DESC, o.id DESC',
        'oldest' => 'o.placed_at ASC, o.id ASC',
        'totalDesc' => 'o.total_minor DESC, o.id DESC',
        'totalAsc' => 'o.total_minor ASC, o.id ASC',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListAdminOrders $query): Page
    {
        if (!$query->requesterIsAdmin) {
            throw new AccessDeniedException('Reading the order list requires an admin role.');
        }

        $conditions = [self::REQUESTER_GUARD];
        $params = ['requester' => $query->requesterId];

        if (null !== $query->q) {
            $conditions[] = "(o.order_number ILIKE :q ESCAPE '\\'
                OR COALESCE(o.customer_name, profile.name) ILIKE :q ESCAPE '\\'
                OR profile.email ILIKE :q ESCAPE '\\'
                OR COALESCE(o.customer_phone, profile.phone) ILIKE :q ESCAPE '\\')";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        if (null !== $query->status) {
            $status = AdminOrderStatus::fromSlug($query->status);
            if (null === $status) {
                // Unknown slug: answer an empty page rather than silently dropping the filter.
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = 'o.status = :status';
                $params['status'] = $status->value;
            }
        }

        if (null !== $query->source) {
            $source = OrderSource::tryFrom(strtoupper($query->source));
            if (null === $source) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = 'o.order_source = :source';
                $params['source'] = $source->value;
            }
        }

        if (null !== $query->dateFrom) {
            $conditions[] = 'o.placed_at >= CAST(:date_from AS date)';
            $params['date_from'] = $query->dateFrom->format('Y-m-d');
        }

        if (null !== $query->dateTo) {
            $conditions[] = "o.placed_at < CAST(:date_to AS date) + INTERVAL '1 day'";
            $params['date_to'] = $query->dateTo->format('Y-m-d');
        }

        $where = implode(' AND ', $conditions);
        $from = 'FROM ordering_orders o LEFT JOIN customer_profile profile ON profile.id = o.customer_id';

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) '.$from.' WHERE '.$where, $params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.id, o.order_number, o.status, o.version, o.order_source,
                    COALESCE(o.customer_name, profile.name) AS contact_name,
                    profile.email AS contact_email,
                    COALESCE(o.customer_phone, profile.phone) AS contact_phone,
                    o.shipping_address, o.admin_notes,
                    o.subtotal_minor, o.discount_minor, o.delivery_minor, o.total_minor,
                    o.currency, o.delivery_method, o.payment_method, o.promo_code, o.placed_at
             '.$from.'
             WHERE '.$where.'
             ORDER BY '.self::ORDER_BY[$query->effectiveSort()].'
             LIMIT :limit OFFSET :offset',
            $params + [
                'limit' => $query->page->effectiveSize(),
                'offset' => $query->page->effectiveOffset(),
            ],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $linesByOrder = $this->linesByOrder($rows);

        return new Page(
            array_map(
                static fn (array $row): AdminOrderView => AdminOrderView::fromRow($row, $linesByOrder[(string) $row['id']] ?? []),
                $rows,
            ),
            $query->page->effectivePage(),
            $query->page->effectiveSize(),
            $total,
        );
    }

    /**
     * @param list<array<string, mixed>> $orders
     *
     * @return array<string, list<AdminCustomerOrderLineView>>
     */
    private function linesByOrder(array $orders): array
    {
        if ([] === $orders) {
            return [];
        }

        /** @var list<string> $orderIds */
        $orderIds = array_map(static fn (array $order): string => (string) $order['id'], $orders);

        $lines = $this->connection->fetchAllAssociative(
            self::SQL_LINES,
            ['ids' => $orderIds],
            ['ids' => ArrayParameterType::STRING],
        );

        /** @var array<string, list<AdminCustomerOrderLineView>> $byOrder */
        $byOrder = [];
        foreach ($lines as $line) {
            $view = AdminCustomerOrderLineView::fromRow($line);
            $byOrder[$view->orderId][] = $view;
        }

        return $byOrder;
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
