<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Shared\Application\Port\StockReservationPort;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ECO-005 — releasing the same order twice (manual cancel racing the auto-cancel) must restore the
 * reserved quantity exactly once. The adapter flips only rows still marked `reserved`.
 */
final class StockReleaseIdempotencyTest extends KernelTestCase
{
    private ?string $slug = null;
    private ?string $orderId = null;

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if (null !== $this->orderId) {
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $this->orderId]);
        }
        if (null !== $this->slug) {
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->slug]);
        }

        parent::tearDown();
    }

    public function testReleasingTwiceRestoresAvailabilityOnce(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $this->slug = 'test-release-idem-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $connection->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, 5, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            FROM storefront_product_view
            WHERE slug = :source',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'slug' => $this->slug,
                'source' => 'baignoire-d-code',
            ],
        );

        /** @var StockReservationPort $stock */
        $stock = $container->get(StockReservationPort::class);

        $this->orderId = Uuid::v7()->toRfc4122();
        $stock->reserve($this->orderId, [['slug' => $this->slug, 'quantity' => 2]]);

        self::assertSame(3, $this->stock($connection), 'the reservation decrements twice');

        $stock->release($this->orderId);
        self::assertSame(5, $this->stock($connection), 'the first release restores both units');

        $stock->release($this->orderId);
        self::assertSame(5, $this->stock($connection), 'the second release must not restore again');

        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM inventory_reservation WHERE order_id = :id',
            ['id' => $this->orderId],
        ));
        self::assertSame('released', $connection->fetchOne(
            'SELECT status FROM inventory_reservation WHERE order_id = :id',
            ['id' => $this->orderId],
        ));
    }

    private function stock(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        );
    }
}
