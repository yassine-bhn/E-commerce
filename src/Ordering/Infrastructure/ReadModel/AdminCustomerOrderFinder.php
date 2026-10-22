<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Application\Query\ListOrdersForAdminRequestedCustomer;
use App\Ordering\Domain\Model\OrderSource;
use App\Shared\Application\Pagination\Cursor;
use App\Shared\Application\Pagination\CursorPage;
use App\Shared\Application\Pagination\TimestampCursor;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Admin dashboard read of one customer's orders (R16) as a keyset page on `(placed_at, id)`.
 *
 * DBAL-only (QRY-003), read-only (QRY-001), authorized on the requester carried by the query
 * (QRY-004) and it returns view DTOs (QRY-002). A page costs three statements whatever its size
 * — the existence check, the orders (`LIMIT + 1` to decide `hasMore`) and one `IN` statement for
 * every line of the page — so the read never degrades into one query per order (PRF-001).
 *
 * Scope (spec Q7 default, assumption 6): registered-customer orders keyed by
 * ordering_orders.customer_id. Phone/WhatsApp proxy orders are denormalized contact snapshots
 * (customer_name / customer_phone / created_by_admin_id) and are excluded by the ONLINE filter.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class AdminCustomerOrderFinder
{
    private const string SQL_CUSTOMER_EXISTS = 'SELECT 1 FROM customer_profile WHERE id = :customer';

    private const string SQL_LINES = 'SELECT order_id, product_slug, product_name, variant_label, unit_price_minor, currency, quantity
                               FROM ordering_order_lines
                               WHERE order_id IN (:ids)
                               ORDER BY id ASC';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListOrdersForAdminRequestedCustomer $query): CursorPage
    {
        $this->ensureAuthorized($query);

        $limit = $query->cursor->effectiveLimit();

        $conditions = ['customer_id = :customer', 'order_source = :source'];
        $params = [
            'customer' => $query->customerId,
            'source' => OrderSource::ONLINE->value,
        ];

        if (null !== $query->status) {
            $statuses = StorefrontOrderStatus::valuesFor($query->status);
            if ([] === $statuses) {
                // Unknown slug: answer an empty page rather than silently dropping the filter.
                $conditions[] = '1 = 0';
            } else {
                $placeholders = [];
                foreach ($statuses as $i => $status) {
                    $placeholders[] = ':status_'.$i;
                    $params['status_'.$i] = $status;
                }
                $conditions[] = 'status IN ('.implode(', ', $placeholders).')';
            }
        }

        if (null !== $query->dateFrom) {
            $conditions[] = 'placed_at >= CAST(:date_from AS date)';
            $params['date_from'] = $query->dateFrom->format('Y-m-d');
        }

        if (null !== $query->dateTo) {
            $conditions[] = "placed_at < CAST(:date_to AS date) + INTERVAL '1 day'";
            $params['date_to'] = $query->dateTo->format('Y-m-d');
        }

        if (null !== $query->q) {
            $conditions[] = "order_number ILIKE :q ESCAPE '\\'";
            $params['q'] = '%'.self::escapeLike($query->q).'%';
        }

        $cursor = TimestampCursor::decode($query->cursor->rawCursor());
        if (null !== $cursor) {
            $conditions[] = '(placed_at, id) < (CAST(:cursor_key AS timestamptz), CAST(:cursor_id AS uuid))';
            $params['cursor_key'] = $cursor['key'];
            $params['cursor_id'] = $cursor['id'];
        }

        /** @var list<array<string, mixed>> $orders */
        $orders = $this->connection->fetchAllAssociative(
            'SELECT id, order_number, status, subtotal_minor, discount_minor, delivery_minor, total_minor,
                    currency, delivery_method, payment_method, promo_code, placed_at
             FROM ordering_orders
             WHERE '.implode(' AND ', $conditions).'
             ORDER BY placed_at DESC, id DESC
             LIMIT '.($limit + 1),
            $params,
        );

        $hasMore = count($orders) > $limit;
        if ($hasMore) {
            $orders = array_slice($orders, 0, $limit);
        }

        $nextCursor = null;
        if ($hasMore && [] !== $orders) {
            $last = $orders[array_key_last($orders)];
            $nextCursor = Cursor::encode((string) $last['placed_at'], (string) $last['id']);
        }

        $linesByOrder = $this->linesByOrder($orders);

        return new CursorPage(
            array_map(
                static fn (array $order): AdminCustomerOrderView => AdminCustomerOrderView::fromRow(
                    $order,
                    $linesByOrder[(string) $order['id']] ?? [],
                ),
                $orders,
            ),
            $limit,
            $hasMore,
            $nextCursor,
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

    private function ensureAuthorized(ListOrdersForAdminRequestedCustomer $query): void
    {
        if (false === $query->requesterIsAdmin) {
            throw new AccessDeniedException('Reading another customer\'s orders requires an admin role.');
        }

        if (false === Uuid::isValid($query->customerId)) {
            throw new NotFoundHttpException('No such customer.');
        }

        if (false === $this->connection->fetchOne(self::SQL_CUSTOMER_EXISTS, ['customer' => $query->customerId])) {
            throw new NotFoundHttpException('No such customer.');
        }
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
