<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\ReadModel;

use App\Ordering\Application\Query\ListOrdersForAdminRequestedCustomer;
use App\Ordering\Domain\Model\OrderSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Admin dashboard read of one customer's orders (R16).
 *
 * DBAL-only (QRY-003), read-only (QRY-001), authorized on the requester carried by the query
 * (QRY-004) and it returns view DTOs (QRY-002). Line items are fetched with one statement for
 * all fetched orders, so the read stays bounded instead of one query per order (PRF-001).
 *
 * Scope (spec Q7 default, assumption 6): registered-customer orders keyed by
 * ordering_orders.customer_id. Phone/WhatsApp proxy orders are denormalized contact snapshots
 * (customer_name / customer_phone / created_by_admin_id) and are excluded by the ONLINE filter.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class AdminCustomerOrderFinder
{
    private const SQL_CUSTOMER_EXISTS = 'SELECT 1 FROM customer_profile WHERE id = :customer';

    private const SQL_ORDERS = 'SELECT id, order_number, status, subtotal_minor, discount_minor, delivery_minor, total_minor,
                                       currency, delivery_method, payment_method, promo_code, placed_at
                                FROM ordering_orders
                                WHERE customer_id = :customer AND order_source = :source
                                ORDER BY placed_at DESC, id DESC';

    private const SQL_LINES = 'SELECT order_id, product_slug, product_name, variant_label, unit_price_minor, currency, quantity
                               FROM ordering_order_lines
                               WHERE order_id IN (:ids)
                               ORDER BY id ASC';

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<AdminCustomerOrderView> */
    public function __invoke(ListOrdersForAdminRequestedCustomer $query): array
    {
        $this->ensureAuthorized($query);

        $orders = $this->connection->fetchAllAssociative(self::SQL_ORDERS, [
            'customer' => $query->customerId,
            'source' => OrderSource::ONLINE->value,
        ]);

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

        /** @var array<string, list<AdminCustomerOrderLineView>> $linesByOrder */
        $linesByOrder = [];
        foreach ($lines as $line) {
            $view = AdminCustomerOrderLineView::fromRow($line);
            $linesByOrder[$view->orderId][] = $view;
        }

        return array_map(
            static fn (array $order): AdminCustomerOrderView => AdminCustomerOrderView::fromRow(
                $order,
                $linesByOrder[(string) $order['id']] ?? [],
            ),
            $orders,
        );
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
}
