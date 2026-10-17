<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Projection;

use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductRegistered;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final class ProjectCatalogProductView
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ProductRegistered|ProductPriceChanged $event): void
    {
        if (!$this->claim($event->eventId)) {
            return;
        }

        if ($event instanceof ProductRegistered) {
            $this->connection->executeStatement(
                'INSERT INTO catalog_product_view (id, name, price_minor, currency) VALUES (:id, :name, :price_minor, :currency)
                 ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, price_minor = EXCLUDED.price_minor, currency = EXCLUDED.currency',
                [
                    'id' => $event->productId,
                    'name' => $event->name,
                    'price_minor' => $event->priceMinor,
                    'currency' => $event->currency,
                ],
            );

            return;
        }

        $this->connection->executeStatement(
            'UPDATE catalog_product_view SET price_minor = :price_minor, currency = :currency WHERE id = :id',
            [
                'id' => $event->productId,
                'price_minor' => $event->priceMinor,
                'currency' => $event->currency,
            ],
        );
    }

    private function claim(string $eventId): bool
    {
        $inserted = $this->connection->executeStatement(
            'INSERT INTO processed_events (consumer, event_id, processed_at) VALUES (:consumer, :event_id, :processed_at)
             ON CONFLICT DO NOTHING',
            [
                'consumer' => self::class,
                'event_id' => $eventId,
                'processed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            ],
        );

        return 1 === $inserted;
    }
}
