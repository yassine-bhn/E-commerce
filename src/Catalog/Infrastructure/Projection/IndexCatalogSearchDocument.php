<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Projection;

use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductRegistered;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * ECO-008 — the catalog write model feeds the storefront search index.
 *
 * The event only carries the product id; the storefront document is read from the
 * `storefront_product_view` read model by that id, so the projection stays a pure
 * read-model → index copy (PRF-003) and never touches Meilisearch inline in a command.
 */
#[AsMessageHandler(bus: 'event.bus')]
final class IndexCatalogSearchDocument
{
    public function __construct(
        private Connection $connection,
        private StorefrontProductSearchIndexer $indexer,
    ) {
    }

    public function __invoke(ProductRegistered|ProductPriceChanged $event): void
    {
        if (!$this->claim($event->eventId)) {
            return;
        }

        $slug = $this->connection->fetchOne(
            'SELECT slug FROM storefront_product_view WHERE id = :id',
            ['id' => $event->productId],
        );
        if (!is_string($slug) || '' === $slug) {
            return;
        }

        // Best-effort: a Meilisearch outage never fails the consumer.
        $this->indexer->sync($slug);
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
