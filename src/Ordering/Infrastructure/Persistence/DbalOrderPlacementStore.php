<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Application\Port\OrderPlacementStore;
use App\Ordering\Domain\Model\Order;
use App\Ordering\Domain\Repository\OrderRepository;
use Doctrine\DBAL\Connection;

/**
 * solid Task 2.7 — the placement adapter: the idempotency claim is the processed_commands INSERT
 * the handler used to run inline, and the order write delegates to the repository so the order
 * aggregate keeps its single persistence owner.
 */
final class DbalOrderPlacementStore implements OrderPlacementStore
{
    public function __construct(
        private Connection $connection,
        private OrderRepository $orders,
    ) {
    }

    public function claim(string $key): bool
    {
        $inserted = $this->connection->executeStatement(
            'INSERT INTO processed_commands (key) VALUES (:key) ON CONFLICT DO NOTHING',
            ['key' => $key],
        );

        return 0 !== $inserted;
    }

    public function save(Order $order): void
    {
        $this->orders->save($order);
    }
}
