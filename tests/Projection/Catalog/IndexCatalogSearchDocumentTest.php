<?php

declare(strict_types=1);

namespace App\Tests\Projection\Catalog;

use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductRegistered;
use App\Catalog\Infrastructure\Projection\IndexCatalogSearchDocument;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** ECO-008 — Meilisearch catalog search projection behaviour (idempotency, unknown product, best-effort). */
final class IndexCatalogSearchDocumentTest extends KernelTestCase
{
    private ?Connection $connection = null;

    /** @var list<string> */
    private array $seededProductIds = [];

    /** @var list<string> */
    private array $createdEventIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        if (!$connection instanceof Connection) {
            self::fail('Doctrine DBAL Connection service is not available.');
        }

        try {
            $connection->executeQuery('SELECT 1 FROM catalog_product_view LIMIT 1');
            $connection->executeQuery('SELECT 1 FROM processed_events LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        $connection = $this->connection;
        if ($connection instanceof Connection) {
            foreach ($this->seededProductIds as $productId) {
                $connection->executeStatement('DELETE FROM catalog_product_view WHERE id = :id', ['id' => $productId]);
            }

            foreach ($this->createdEventIds as $eventId) {
                $connection->executeStatement(
                    'DELETE FROM processed_events WHERE consumer = :consumer AND event_id = :event_id',
                    ['consumer' => IndexCatalogSearchDocument::class, 'event_id' => $eventId],
                );
            }
        }

        $this->seededProductIds = [];
        $this->createdEventIds = [];
        $this->connection = null;

        parent::tearDown();
    }

    public function testDuplicateEventIsClaimedOnlyOnce(): void
    {
        $productId = Uuid::v4()->toRfc4122();
        $eventId = Uuid::v4()->toRfc4122();
        $this->seedProductView($productId, 'Duplicate Widget', 1999, 'EUR');
        $this->createdEventIds[] = $eventId;

        $spy = CountingConnection::wrap($this->connection());
        $handler = new IndexCatalogSearchDocument($spy, 'http://127.0.0.1:1');
        $event = new ProductRegistered($eventId, $productId, 'Duplicate Widget', 1999, 'EUR', new \DateTimeImmutable());

        $handler($event);

        self::assertSame(1, $this->countProcessedEvents($eventId), 'first dispatch must record exactly one claim');
        self::assertSame(1, $spy->fetchAssociativeCalls, 'first dispatch must read the product view once');

        $handler($event);

        self::assertSame(1, $this->countProcessedEvents($eventId), 'duplicate dispatch must not create a second claim');
        self::assertSame(1, $spy->fetchAssociativeCalls, 'duplicate dispatch must return before reading the product view');
    }

    public function testUnknownProductIdReturnsWithoutSideEffects(): void
    {
        $productId = Uuid::v4()->toRfc4122();
        $eventId = Uuid::v4()->toRfc4122();
        $this->createdEventIds[] = $eventId;

        self::assertFalse(
            $this->connection()->fetchAssociative(
                'SELECT id FROM catalog_product_view WHERE id = :id',
                ['id' => $productId],
            ),
            'precondition: no product view row must exist for an unknown product',
        );

        $handler = new IndexCatalogSearchDocument($this->connection(), 'http://127.0.0.1:1');
        $event = new ProductRegistered($eventId, $productId, 'Ghost Product', 100, 'EUR', new \DateTimeImmutable());

        $handler($event);

        self::assertSame(1, $this->countProcessedEvents($eventId), 'event must still be claimed before the missing product is detected');
    }

    public function testUnreachableMeilisearchIsBestEffort(): void
    {
        $productId = Uuid::v4()->toRfc4122();
        $eventId = Uuid::v4()->toRfc4122();
        $this->seedProductView($productId, 'Best Effort Widget', 4200, 'EUR');
        $this->createdEventIds[] = $eventId;

        $handler = new IndexCatalogSearchDocument($this->connection(), 'http://127.0.0.1:1');
        $event = new ProductPriceChanged($eventId, $productId, 4200, 'EUR', new \DateTimeImmutable());

        try {
            $handler($event);
        } catch (\Throwable $exception) {
            self::fail('ECO-008: indexing must never throw, got: '.$exception->getMessage());
        }

        self::assertSame(1, $this->countProcessedEvents($eventId), 'ECO-008: indexing failure must not roll back the claim');
    }

    private function connection(): Connection
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            self::fail('Database connection is not available.');
        }

        return $connection;
    }

    private function seedProductView(string $productId, string $name, int $priceMinor, string $currency): void
    {
        $this->connection()->insert('catalog_product_view', [
            'id' => $productId,
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
        ]);

        $this->seededProductIds[] = $productId;
    }

    private function countProcessedEvents(string $eventId): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM processed_events WHERE consumer = :consumer AND event_id = :event_id',
            ['consumer' => IndexCatalogSearchDocument::class, 'event_id' => $eventId],
        );
    }
}

/**
 * Counts the read-model lookups issued by the handler so the claim guard can be asserted precisely.
 */
final class CountingConnection extends Connection
{
    public int $fetchAssociativeCalls = 0;

    private Connection $inner;

    public static function wrap(Connection $inner): self
    {
        $spy = new self($inner->getParams(), $inner->getDriver());
        $spy->inner = $inner;

        return $spy;
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        ++$this->fetchAssociativeCalls;

        return $this->inner->fetchAssociative($query, $params, $types);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return $this->inner->executeStatement($sql, $params, $types);
    }
}
