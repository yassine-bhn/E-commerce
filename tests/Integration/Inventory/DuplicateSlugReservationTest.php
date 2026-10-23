<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Shared\Application\Port\StockReservationPort;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ECO-004 — one product may sit on two cart lines when the customer picks two variants, and
 * the reservation is keyed by (order_id, product_slug). Both lines must therefore be reserved
 * and released as one summed quantity, never as the first line alone.
 */
final class DuplicateSlugReservationTest extends KernelTestCase
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

    public function testTwoLinesOfTheSameProductReserveTheSummedQuantity(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $this->slug = 'test-duplicate-slug-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $connection->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, 5, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
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
        $stock->reserve($this->orderId, [
            ['slug' => $this->slug, 'quantity' => 2],
            ['slug' => $this->slug, 'quantity' => 3],
        ]);

        self::assertSame(0, $this->stock($connection), 'both lines of the product are reserved');
        self::assertSame(5, (int) $connection->fetchOne(
            'SELECT quantity FROM inventory_reservation WHERE order_id = :id',
            ['id' => $this->orderId],
        ), 'the reservation carries the summed quantity');

        $stock->release($this->orderId);
        self::assertSame(5, $this->stock($connection), 'the release restores what both lines took');
    }

    private function stock(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        );
    }
}
