<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Projection;

use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductRegistered;
use Doctrine\DBAL\Connection;
use Meilisearch\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** ECO-008 — Meilisearch catalog search projection. */
#[AsMessageHandler(bus: 'event.bus')]
final class IndexCatalogSearchDocument
{
    public function __construct(
        private Connection $connection,
        #[Autowire(env: 'MEILISEARCH_URL')]
        private string $meilisearchUrl,
    ) {
    }

    public function __invoke(ProductRegistered|ProductPriceChanged $event): void
    {
        if (!$this->claim($event->eventId)) {
            return;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, name, price_minor, currency FROM catalog_product_view WHERE id = :id',
            ['id' => $event->productId],
        );
        if (false === $row) {
            return;
        }

        try {
            $client = new Client($this->meilisearchUrl);
            $client->index('catalog_products')->addDocuments([
                [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'price_minor' => (int) $row['price_minor'],
                    'currency' => $row['currency'],
                ],
            ], 'id');
        } catch (\Throwable) {
            // Meilisearch indexing optional/best-effort
        }
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
