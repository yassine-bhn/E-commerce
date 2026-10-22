<?php

declare(strict_types=1);

namespace App\Tests\Projection\Catalog;

use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductRegistered;
use App\Catalog\Infrastructure\Projection\IndexCatalogSearchDocument;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** ECO-008 — Meilisearch storefront search projection (idempotency, unknown product, best-effort). */
final class IndexCatalogSearchDocumentTest extends KernelTestCase
{
    private ?Connection $connection = null;

    /** @var list<string> */
    private array $seededSlugs = [];

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
            $connection->executeQuery('SELECT 1 FROM storefront_product_view LIMIT 1');
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
            foreach ($this->seededSlugs as $slug) {
                $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $slug]);
            }

            foreach ($this->createdEventIds as $eventId) {
                $connection->executeStatement(
                    'DELETE FROM processed_events WHERE consumer = :consumer AND event_id = :event_id',
                    ['consumer' => IndexCatalogSearchDocument::class, 'event_id' => $eventId],
                );
            }
        }

        $this->seededSlugs = [];
        $this->createdEventIds = [];
        $this->connection = null;

        parent::tearDown();
    }

    public function testDuplicateEventIsClaimedOnlyOnce(): void
    {
        $productId = Uuid::v4()->toRfc4122();
        $eventId = Uuid::v4()->toRfc4122();
        $this->seedProductView($productId, 'duplicate-widget-'.Uuid::v4()->toRfc4122(), 'Duplicate Widget');
        $this->createdEventIds[] = $eventId;

        $spy = CountingConnection::wrap($this->connection());
        $handler = new IndexCatalogSearchDocument($spy, $this->indexer($spy));
        $event = new ProductRegistered($eventId, $productId, 'Duplicate Widget', 1999, 'EUR', new \DateTimeImmutable());

        $handler($event);

        self::assertSame(1, $this->countProcessedEvents($eventId), 'first dispatch must record exactly one claim');
        self::assertSame(1, $spy->fetchOneCalls, 'first dispatch must resolve the storefront slug once');

        $handler($event);

        self::assertSame(1, $this->countProcessedEvents($eventId), 'duplicate dispatch must not create a second claim');
        self::assertSame(1, $spy->fetchOneCalls, 'duplicate dispatch must return before resolving the slug');
    }

    public function testUnknownProductIdReturnsWithoutSideEffects(): void
    {
        $productId = Uuid::v4()->toRfc4122();
        $eventId = Uuid::v4()->toRfc4122();
        $this->createdEventIds[] = $eventId;

        self::assertFalse(
            $this->connection()->fetchOne(
                'SELECT slug FROM storefront_product_view WHERE id = :id',
                ['id' => $productId],
            ),
            'precondition: no storefront row must exist for an unknown product',
        );

        $http = new RecordingHttpClient();
        $handler = new IndexCatalogSearchDocument($this->connection(), $this->indexer($this->connection(), $http));
        $event = new ProductRegistered($eventId, $productId, 'Ghost Product', 100, 'EUR', new \DateTimeImmutable());

        $handler($event);

        self::assertSame(1, $this->countProcessedEvents($eventId), 'event must still be claimed before the missing product is detected');
        self::assertSame([], $http->requests, 'no document must be pushed for a product absent from the storefront view');
    }

    public function testPriceChangeReindexesTheStorefrontDocumentBySlug(): void
    {
        $productId = Uuid::v4()->toRfc4122();
        $slug = 'index-projection-'.Uuid::v4()->toRfc4122();
        $eventId = Uuid::v4()->toRfc4122();
        $this->seedProductView($productId, $slug, 'Projected Widget');
        $this->createdEventIds[] = $eventId;

        $http = new RecordingHttpClient();
        $handler = new IndexCatalogSearchDocument($this->connection(), $this->indexer($this->connection(), $http));
        $event = new ProductPriceChanged($eventId, $productId, 4200, 'EUR', new \DateTimeImmutable());

        $handler($event);

        self::assertCount(1, $http->requests);
        /** @var list<array<string, mixed>> $documents */
        $documents = json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($slug, $documents[0]['id'], 'the storefront document is keyed by slug');
    }

    public function testUnreachableMeilisearchIsBestEffort(): void
    {
        $productId = Uuid::v4()->toRfc4122();
        $eventId = Uuid::v4()->toRfc4122();
        $this->seedProductView($productId, 'best-effort-'.Uuid::v4()->toRfc4122(), 'Best Effort Widget');
        $this->createdEventIds[] = $eventId;

        $indexer = $this->indexer($this->connection(), new RecordingHttpClient(fail: true));
        $handler = new IndexCatalogSearchDocument($this->connection(), $indexer);
        $event = new ProductPriceChanged($eventId, $productId, 4200, 'EUR', new \DateTimeImmutable());

        try {
            $handler($event);
        } catch (\Throwable $exception) {
            self::fail('ECO-008: indexing must never throw, got: '.$exception->getMessage());
        }

        self::assertSame(1, $this->countProcessedEvents($eventId), 'ECO-008: indexing failure must not roll back the claim');
    }

    private function indexer(Connection $connection, ?RecordingHttpClient $http = null): StorefrontProductSearchIndexer
    {
        return new StorefrontProductSearchIndexer(
            $connection,
            new Client('http://meilisearch:7700', null, $http ?? new RecordingHttpClient(fail: true)),
        );
    }

    private function connection(): Connection
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            self::fail('Database connection is not available.');
        }

        return $connection;
    }

    private function seedProductView(string $productId, string $slug, string $name): void
    {
        $this->connection()->insert('storefront_product_view', [
            'id' => $productId,
            'slug' => $slug,
            'name' => $name,
            'brand' => 'Zidane',
            'ref' => 'ZID-001',
            'category_slug' => 'chaises',
            'subcategory' => 'Chaises de bureau',
            'price_minor' => 1999,
            'old_price_minor' => null,
            'currency' => 'EUR',
            'stock' => 'in',
            'material' => 'Acier',
            'dims' => '60x60x100',
            'image_path' => '/uploads/products/fixture.png',
            'rating' => 4.0,
            'reviews' => 3,
            'is_new' => true,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Fixture product',
            'variants' => '[]',
        ], [
            'price_minor' => ParameterType::INTEGER,
            'rating' => ParameterType::STRING,
            'reviews' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
        ]);

        $this->seededSlugs[] = $slug;
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
 * Counts the storefront slug lookups issued by the handler so the claim guard can be asserted precisely.
 */
final class CountingConnection extends Connection
{
    public int $fetchOneCalls = 0;

    private Connection $inner;

    public static function wrap(Connection $inner): self
    {
        $spy = new self($inner->getParams(), $inner->getDriver());
        $spy->inner = $inner;

        return $spy;
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        ++$this->fetchOneCalls;

        return $this->inner->fetchOne($query, $params, $types);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return $this->inner->executeStatement($sql, $params, $types);
    }
}
