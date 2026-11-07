<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-15 WP4 (R14/R17) — the admin product form writes stock through the Inventory port.
 *
 * `stockQuantity` means *available*: a save with an existing reservation of three and an entered
 * five leaves five sellable units and a balance of eight on hand. The view column is only the
 * synchronous cache, and deleting the product removes both rows in the same transaction.
 */
final class AdminProductStockTest extends WebTestCase
{
    private ?string $slug = null;
    private ?string $orderId = null;
    private ?string $adminId = null;

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if (null !== $this->orderId) {
            $connection->executeStatement('DELETE FROM inventory_reservation WHERE order_id = :id', ['id' => $this->orderId]);
        }
        if (null !== $this->slug) {
            $connection->executeStatement('DELETE FROM inventory_stock WHERE product_slug = :slug', ['slug' => $this->slug]);
            $connection->executeStatement('DELETE FROM storefront_product_view WHERE slug = :slug', ['slug' => $this->slug]);
        }
        if (null !== $this->adminId) {
            $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => $this->adminId]);
        }

        parent::tearDown();
    }

    public function testAdminSetAvailableKeepsAnExistingReservation(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createSuperAdmin());

        $this->createProduct($client, 5);
        self::assertSame(['on_hand' => 5, 'reserved' => 0], $this->balance(), 'creation initialises the balance from stockQuantity');
        self::assertSame(5, $this->cache());

        $this->seedReservation(3);

        $body = $this->payload();
        $body['stockQuantity'] = 5;
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$this->slug, $body);
        self::assertResponseIsSuccessful();

        self::assertSame(['on_hand' => 8, 'reserved' => 3], $this->balance(), 'on_hand = available + reserved');
        self::assertSame(5, $this->cache(), 'the cache shows the available quantity');
        self::assertSame(5, $this->json($client)['stockQuantity']);
    }

    public function testDeletingAProductRemovesItsBalanceRow(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createSuperAdmin());

        $this->createProduct($client, 4);
        self::assertSame(['on_hand' => 4, 'reserved' => 0], $this->balance());
        self::assertSame(4, $this->cache());

        $client->request('DELETE', '/api/v1/admin/catalog/products/'.$this->slug);
        self::assertResponseIsSuccessful();

        self::assertFalse($this->balanceRow(), 'the balance row dies with its product');
        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        ));
    }

    private function createProduct(KernelBrowser $client, int $stockQuantity): void
    {
        $this->slug = 'test-admin-stock-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $body = $this->payload();
        $body['stockQuantity'] = $stockQuantity;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($stockQuantity, $this->json($client)['stockQuantity']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'slug' => $this->slug,
            'name' => 'Produit stock admin',
            'brand' => 'Sopal',
            'ref' => 'TST-STOCK',
            'category' => 'sanitaires',
            'sub' => 'WC',
            'price' => 200,
            'oldPrice' => null,
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image' => '/p-wc.jpg',
            'isNew' => true,
            'best' => false,
            'headrest' => false,
            'description' => 'Produit créé par un test de stock.',
            'variants' => [],
        ];
    }

    private function seedReservation(int $quantity): void
    {
        $connection = $this->connection();
        $this->orderId = Uuid::v7()->toRfc4122();

        $connection->executeStatement(
            'INSERT INTO inventory_reservation (order_id, product_slug, quantity)
             VALUES (:order_id, :slug, :quantity)',
            ['order_id' => $this->orderId, 'slug' => $this->slug, 'quantity' => $quantity],
        );
        $connection->executeStatement(
            'UPDATE inventory_stock SET reserved = :quantity WHERE product_slug = :slug',
            ['quantity' => $quantity, 'slug' => $this->slug],
        );
        $connection->executeStatement(
            'UPDATE storefront_product_view SET stock_quantity = 2 WHERE slug = :slug',
            ['slug' => $this->slug],
        );
    }

    private function createSuperAdmin(): CustomerUser
    {
        $connection = $this->connection();
        $this->adminId = Uuid::v4()->toRfc4122();
        $email = 'admin.stock.'.substr($this->adminId, 0, 8).'@example.com';

        $connection->insert('customer_profile', [
            'id' => $this->adminId,
            'name' => 'Admin Stock Test',
            'email' => $email,
            'role' => 'ROLE_SUPER_ADMIN',
            'status' => 'active',
        ]);

        return new CustomerUser($this->adminId, $email, null, 'ROLE_SUPER_ADMIN', 'active');
    }

    /** @return array{on_hand: int, reserved: int} */
    private function balance(): array
    {
        /** @var array{on_hand: int, reserved: int}|false $row */
        $row = $this->connection()->fetchAssociative(
            'SELECT on_hand, reserved FROM inventory_stock WHERE product_slug = :slug',
            ['slug' => $this->slug],
        );
        self::assertIsArray($row, 'the product has an authoritative inventory_stock row');

        return ['on_hand' => $row['on_hand'], 'reserved' => $row['reserved']];
    }

    private function balanceRow(): bool
    {
        return false !== $this->connection()->fetchOne(
            'SELECT 1 FROM inventory_stock WHERE product_slug = :slug',
            ['slug' => $this->slug],
        );
    }

    private function cache(): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        );

        return false === $value || null === $value ? null : (int) $value;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }
}
