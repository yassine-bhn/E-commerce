<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Outbox\OutboxPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineOutbox implements OutboxPort
{
    public function __construct(
        private Connection $connection,
        private SerializerInterface $serializer,
    ) {
    }

    public function append(string $aggregateType, string $aggregateId, array $domainEvents): void
    {
        foreach ($domainEvents as $event) {
            $this->connection->insert('outbox', [
                'id' => Uuid::v7()->toRfc4122(),
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'event_type' => $event::class,
                'payload' => $this->serializer->serialize($event, 'json'),
                'occurred_on' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
                'published_at' => null,
            ]);
        }
    }
}
