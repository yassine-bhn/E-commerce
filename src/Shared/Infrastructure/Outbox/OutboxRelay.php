<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

/** Publishes unpublished outbox rows to event.bus (EVT-002 / BUS-002). */
final class OutboxRelay
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $eventBus,
        private SerializerInterface $serializer,
    ) {
    }

    public function publishBatch(int $limit = 50): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, event_type, payload FROM outbox WHERE published_at IS NULL ORDER BY occurred_on ASC LIMIT ?',
            [$limit],
        );

        $count = 0;
        foreach ($rows as $row) {
            $payload = (string) $row['payload'];
            if (str_starts_with($payload, '"')) {
                $decoded = json_decode($payload, true);
                if (is_string($decoded)) {
                    $payload = $decoded;
                }
            }
            $event = $this->serializer->deserialize($payload, $row['event_type'], 'json');
            $this->eventBus->dispatch($event);
            $this->connection->update('outbox', [
                'published_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ], ['id' => $row['id']]);
            ++$count;
        }

        return $count;
    }
}
