<?php

declare(strict_types=1);

namespace App\Tests\Projection\Catalog;

use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductRegistered;
use App\Catalog\Infrastructure\Projection\ProjectCatalogProductView;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * EVT-003 / MIG-002 — Catalog product-view projection correctness, idempotency and replayability.
 */
final class ProjectCatalogProductViewTest extends KernelTestCase
{
    private ?Connection $connection = null;

    private ?ProjectCatalogProductView $projector = null;

    /** @var list<string> */
    private array $productIds = [];

    /** @var list<string> */
    private array $eventIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $this->connection = $connection;

        /** @var ProjectCatalogProductView $projector */
        $projector = $container->get(ProjectCatalogProductView::class);
        $this->projector = $projector;
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection) {
            foreach ($this->productIds as $productId) {
                $this->connection->executeStatement(
                    'DELETE FROM catalog_product_view WHERE id = :id',
                    ['id' => $productId],
                );
            }

            foreach ($this->eventIds as $eventId) {
                $this->connection->executeStatement(
                    'DELETE FROM processed_events WHERE consumer = :consumer AND event_id = :event_id',
                    ['consumer' => ProjectCatalogProductView::class, 'event_id' => $eventId],
                );
            }
        }

        parent::tearDown();
    }

    public function testProductRegisteredCreatesProjectionRow(): void
    {
        $this->requireSchema();

        $productId = $this->newProductId();

        ($this->projector())(new ProductRegistered(
            eventId: $this->newEventId(),
            productId: $productId,
            name: 'Projected Widget',
            priceMinor: 1999,
            currency: 'EUR',
            occurredOn: new \DateTimeImmutable(),
        ));

        $row = $this->fetchViewRow($productId);
        self::assertNotNull($row);
        self::assertSame('Projected Widget', $row['name']);
        self::assertSame(1999, (int) $row['price_minor']);
        self::assertSame('EUR', $row['currency']);
    }

    public function testRepeatedProductRegisteredUpsertsExistingRow(): void
    {
        $this->requireSchema();

        $productId = $this->newProductId();
        $projector = $this->projector();

        $projector(new ProductRegistered($this->newEventId(), $productId, 'First Name', 1000, 'EUR', new \DateTimeImmutable()));
        $projector(new ProductRegistered($this->newEventId(), $productId, 'Second Name', 2500, 'USD', new \DateTimeImmutable()));

        $count = (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM catalog_product_view WHERE id = :id',
            ['id' => $productId],
        );
        self::assertSame(1, $count);

        $row = $this->fetchViewRow($productId);
        self::assertNotNull($row);
        self::assertSame('Second Name', $row['name']);
        self::assertSame(2500, (int) $row['price_minor']);
        self::assertSame('USD', $row['currency']);
    }

    public function testProductPriceChangedUpdatesPriceAndCurrencyAndLeavesNameUntouched(): void
    {
        $this->requireSchema();

        $productId = $this->newProductId();
        $projector = $this->projector();

        $projector(new ProductRegistered($this->newEventId(), $productId, 'Stable Name', 1000, 'EUR', new \DateTimeImmutable()));
        $projector(new ProductPriceChanged($this->newEventId(), $productId, 3000, 'USD', new \DateTimeImmutable()));

        $row = $this->fetchViewRow($productId);
        self::assertNotNull($row);
        self::assertSame('Stable Name', $row['name']);
        self::assertSame(3000, (int) $row['price_minor']);
        self::assertSame('USD', $row['currency']);
    }

    public function testProductPriceChangedForUnknownProductIsNoOp(): void
    {
        $this->requireSchema();

        $productId = $this->newProductId();

        ($this->projector())(new ProductPriceChanged(
            eventId: $this->newEventId(),
            productId: $productId,
            priceMinor: 3000,
            currency: 'USD',
            occurredOn: new \DateTimeImmutable(),
        ));

        self::assertNull($this->fetchViewRow($productId));
        $count = (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM catalog_product_view WHERE id = :id',
            ['id' => $productId],
        );
        self::assertSame(0, $count);
    }

    public function testSameEventDeliveredTwiceAppliesExactlyOneEffect(): void
    {
        $this->requireSchema();

        $productId = $this->newProductId();
        $eventId = $this->newEventId();
        $projector = $this->projector();

        $event = new ProductRegistered($eventId, $productId, 'Original Name', 1000, 'EUR', new \DateTimeImmutable());

        $projector($event);

        // Mutate the read model so a second (wrong) application of the same event would be observable.
        $this->connection()->executeStatement(
            'UPDATE catalog_product_view SET name = :name WHERE id = :id',
            ['name' => 'Mutated In Between', 'id' => $productId],
        );

        $projector($event);

        $row = $this->fetchViewRow($productId);
        self::assertNotNull($row);
        self::assertSame('Mutated In Between', $row['name'], 'Second delivery of the same eventId must not apply the effect again (EVT-003)');

        $claims = (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM processed_events WHERE consumer = :consumer AND event_id = :event_id',
            ['consumer' => ProjectCatalogProductView::class, 'event_id' => $eventId],
        );
        self::assertSame(1, $claims, 'Exactly one processed_events claim is expected for the consumer and event id (EVT-003)');
    }

    public function testReplayingEventSequenceRebuildsIncrementalState(): void
    {
        $this->requireSchema();

        $productId = $this->newProductId();
        $projector = $this->projector();

        $registered = new ProductRegistered($this->newEventId(), $productId, 'Replay Widget', 1200, 'EUR', new \DateTimeImmutable());
        $priceChanged = new ProductPriceChanged($this->newEventId(), $productId, 4500, 'USD', new \DateTimeImmutable());

        $projector($registered);
        $projector($priceChanged);

        $incremental = $this->fetchViewRow($productId);
        self::assertNotNull($incremental);

        // Clear the projection state and its event claims, then replay the very same event sequence.
        $this->connection()->executeStatement('DELETE FROM catalog_product_view WHERE id = :id', ['id' => $productId]);

        foreach ([$registered->eventId, $priceChanged->eventId] as $eventId) {
            $this->connection()->executeStatement(
                'DELETE FROM processed_events WHERE consumer = :consumer AND event_id = :event_id',
                ['consumer' => ProjectCatalogProductView::class, 'event_id' => $eventId],
            );
        }

        $projector($registered);
        $projector($priceChanged);

        $replayed = $this->fetchViewRow($productId);

        self::assertSame($incremental, $replayed, 'Replaying the same events must rebuild an identical row (MIG-002)');
    }

    private function requireSchema(): void
    {
        try {
            $this->connection()->executeQuery('SELECT 1 FROM catalog_product_view LIMIT 1');
            $this->connection()->executeQuery('SELECT 1 FROM processed_events LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchViewRow(string $productId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT id, name, price_minor, currency FROM catalog_product_view WHERE id = :id',
            ['id' => $productId],
        );

        return false === $row ? null : $row;
    }

    private function newProductId(): string
    {
        $productId = Uuid::v4()->toRfc4122();
        $this->productIds[] = $productId;

        return $productId;
    }

    private function newEventId(): string
    {
        $eventId = Uuid::v4()->toRfc4122();
        $this->eventIds[] = $eventId;

        return $eventId;
    }

    private function connection(): Connection
    {
        $connection = $this->connection;
        if (!$connection instanceof Connection) {
            throw new \LogicException('The DBAL connection was not bootstrapped by setUp().');
        }

        return $connection;
    }

    private function projector(): ProjectCatalogProductView
    {
        $projector = $this->projector;
        if (!$projector instanceof ProjectCatalogProductView) {
            throw new \LogicException('The projector was not resolved from the container by setUp().');
        }

        return $projector;
    }
}
