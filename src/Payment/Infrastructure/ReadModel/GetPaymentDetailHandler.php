<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\ReadModel;

use App\Payment\Application\Query\GetPaymentDetail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The payment is read back with its whole ledger: the operator needs the history,
 * not just the current state (BRS §二.4 — an independent lifecycle with an audit trail).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetPaymentDetailHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, mixed>|null */
    public function __invoke(GetPaymentDetail $query): ?array
    {
        $sql = 'SELECT p.id, p.order_id, p.method, p.amount_minor, p.currency, p.status, p.version,
                       p.provider_reference, o.customer_id
                FROM payment p JOIN ordering_orders o ON o.id = p.order_id
                WHERE p.id = :id';
        $params = ['id' => $query->paymentId];

        if (!$query->isAdmin) {
            $sql .= ' AND o.customer_id = :requester';
            $params['requester'] = $query->requesterId;
        }

        $payment = $this->connection->fetchAssociative($sql, $params);
        if (false === $payment) {
            return null;
        }

        $ledger = $this->connection->fetchAllAssociative(
            'SELECT from_status, to_status, version, amount_minor, actor_id, reason, created_at
             FROM payment_ledger WHERE payment_id = :id ORDER BY version ASC, created_at ASC',
            ['id' => $payment['id']],
        );

        return [
            'paymentId' => (string) $payment['id'],
            'orderId' => (string) $payment['order_id'],
            'method' => (string) $payment['method'],
            'amountMinor' => (int) $payment['amount_minor'],
            'currency' => (string) $payment['currency'],
            'status' => (string) $payment['status'],
            'version' => (int) $payment['version'],
            'providerReference' => $payment['provider_reference'],
            'ledger' => array_map(static fn (array $row): array => [
                'fromStatus' => $row['from_status'],
                'toStatus' => (string) $row['to_status'],
                'version' => (int) $row['version'],
                'amountMinor' => (int) $row['amount_minor'],
                'actorId' => (string) $row['actor_id'],
                'reason' => $row['reason'],
                'createdAt' => (string) $row['created_at'],
            ], $ledger),
        ];
    }
}
