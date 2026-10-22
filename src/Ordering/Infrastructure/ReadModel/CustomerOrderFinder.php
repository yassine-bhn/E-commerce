<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Application\Query\ListOrdersForCustomer;
use App\Shared\Application\Pagination\Cursor;
use App\Shared\Application\Pagination\CursorPage;
use App\Shared\Application\Pagination\TimestampCursor;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R7 — the authenticated customer's order history as a keyset page on `(placed_at, id)`.
 *
 * PRF-001: a page costs two statements whatever its size — one for the orders (`LIMIT + 1` to
 * decide `hasMore`) and one `IN` statement for every line of that page. The former per-order
 * line query is gone.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class CustomerOrderFinder
{
    private const string SQL_LINES = 'SELECT order_id, product_slug, product_name, variant_label, unit_price_minor, quantity
                                       FROM ordering_order_lines
                                       WHERE order_id IN (:ids)
                                       ORDER BY product_name ASC, id ASC';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ListOrdersForCustomer $query): CursorPage
    {
        $limit = $query->cursor->effectiveLimit();

        $conditions = ['customer_id = :customer'];
        $params = ['customer' => $query->customerId];

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
            'SELECT id, order_number, status, subtotal_minor, discount_minor, delivery_minor, total_minor, currency,
                    delivery_method, payment_method, promo_code, placed_at
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
                static fn (array $order): array => self::item($order, $linesByOrder[(string) $order['id']] ?? []),
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
     * @return array<string, list<array<string, mixed>>>
     */
    private function linesByOrder(array $orders): array
    {
        if ([] === $orders) {
            return [];
        }

        $orderIds = array_map(static fn (array $order): string => (string) $order['id'], $orders);

        /** @var list<array<string, mixed>> $lines */
        $lines = $this->connection->fetchAllAssociative(
            self::SQL_LINES,
            ['ids' => $orderIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $byOrder = [];
        foreach ($lines as $line) {
            $byOrder[(string) $line['order_id']][] = [
                'slug' => (string) $line['product_slug'],
                'variant' => $line['variant_label'],
                'price' => (int) $line['unit_price_minor'],
                'qty' => (int) $line['quantity'],
            ];
        }

        return $byOrder;
    }

    /**
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private static function item(array $order, array $lines): array
    {
        return [
            'id' => (string) $order['order_number'],
            'orderId' => (string) $order['id'],
            'date' => substr((string) $order['placed_at'], 0, 10),
            'status' => StorefrontOrderStatus::label((string) $order['status']),
            'subtotal' => (int) $order['subtotal_minor'],
            'discount' => (int) $order['discount_minor'],
            'delivery' => (int) $order['delivery_minor'],
            'total' => (int) $order['total_minor'],
            'currency' => (string) $order['currency'],
            'method' => (string) $order['delivery_method'],
            'payment' => (string) $order['payment_method'],
            'promoCode' => $order['promo_code'],
            'lines' => $lines,
        ];
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
