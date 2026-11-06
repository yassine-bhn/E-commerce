<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\ReadModel;

use App\Payment\Application\Query\GetPaymentState;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.8 — the payment's read-side state (status, version and the order status it
 * settles), through the query bus. An unknown payment answers the same unknown fallback the
 * admin route always returned, so the dashboard shows a placeholder instead of a 404.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetPaymentStateHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{paymentId: string, status: string, version: int, orderStatus: string} */
    public function __invoke(GetPaymentState $query): array
    {
        $payment = $this->connection->fetchAssociative(
            'SELECT p.status, p.version, o.status AS order_status
             FROM payment p JOIN ordering_orders o ON o.id = p.order_id
             WHERE p.id = :id',
            ['id' => $query->paymentId],
        );

        if (false === $payment) {
            return ['paymentId' => $query->paymentId, 'status' => 'unknown', 'version' => 0, 'orderStatus' => 'unknown'];
        }

        return [
            'paymentId' => $query->paymentId,
            'status' => (string) $payment['status'],
            'version' => (int) $payment['version'],
            'orderStatus' => (string) $payment['order_status'],
        ];
    }
}
