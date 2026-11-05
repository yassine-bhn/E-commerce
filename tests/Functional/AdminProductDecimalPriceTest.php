<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * R36 — the admin product API takes decimal DT with up to three decimals and stores millimes.
 * Responses keep the integer millime contract.
 */
final class AdminProductDecimalPriceTest extends WebTestCase
{
    private const string ADMIN_ID = '01929312-7000-7000-8000-000000000095';
    private const string ADMIN_EMAIL = 'decimal.price@example.com';

    /** @var list<string> */
    private array $slugs = [];

    protected function tearDown(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        if ([] !== $this->slugs) {
            $connection->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
                ['slugs' => $this->slugs],
                ['slugs' => ArrayParameterType::STRING],
            );
        }
        $connection->executeStatement('DELETE FROM customer_profile WHERE id = :id', ['id' => self::ADMIN_ID]);

        parent::tearDown();
    }

    public function testCreateAndUpdateAcceptDecimalDinarPricesAndStoreMillimes(): void
    {
        $client = $this->client();
        $slug = $this->slug('decimal-price');

        $payload = $this->payload($slug);
        $payload['price'] = '15.3';
        $payload['oldPrice'] = 153894.32;

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $created = $this->json($client);
        self::assertSame(15300, $created['price']);
        self::assertSame(153894320, $created['oldPrice']);
        self::assertSame(15300, $this->storedMinor($slug, 'price_minor'));
        self::assertSame(153894320, $this->storedMinor($slug, 'old_price_minor'));

        $payload['price'] = 153894.32;
        $payload['oldPrice'] = null;
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $payload);
        self::assertResponseIsSuccessful();

        $updated = $this->json($client);
        self::assertSame(153894320, $updated['price']);
        self::assertSame(153894320, $this->storedMinor($slug, 'price_minor'));
    }

    public function testVariantPricesAcceptDecimalsAndKeepTheLowestPriceAsBase(): void
    {
        $client = $this->client();
        $slug = $this->slug('decimal-variants');

        $payload = $this->payload($slug);
        $payload['price'] = 9999;
        $payload['variants'] = [
            ['label' => '50 L', 'price' => '15.3'],
            ['label' => '80 L', 'price' => 153894.32],
        ];

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $created = $this->json($client);
        self::assertSame(15300, $created['price']);
        self::assertSame(
            [['label' => '50 L', 'price' => 15300], ['label' => '80 L', 'price' => 153894320]],
            $created['variants'],
        );

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        /** @var list<array{label: string, price: int}> $variants */
        $variants = json_decode((string) $connection->fetchOne(
            'SELECT variants FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        ), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            [['label' => '50 L', 'price' => 15300], ['label' => '80 L', 'price' => 153894320]],
            $variants,
        );
    }

    public function testSubMillimePriceIsRejected(): void
    {
        $client = $this->client();
        $slug = $this->slug('decimal-rejected');

        $payload = $this->payload($slug);
        $payload['price'] = '1.2345';

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        self::assertFalse($connection->fetchOne(
            'SELECT 1 FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        ));
    }

    public function testNegativeDecimalPriceIsRejected(): void
    {
        $client = $this->client();
        $slug = $this->slug('decimal-negative');

        $payload = $this->payload($slug);
        $payload['price'] = '-1.5';

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'INSERT INTO customer_profile (id, name, email, role, status)
             VALUES (:id, :name, :email, :role, :status)
             ON CONFLICT DO NOTHING',
            [
                'id' => self::ADMIN_ID,
                'name' => 'Decimal Price Test',
                'email' => self::ADMIN_EMAIL,
                'role' => 'ROLE_SUPER_ADMIN',
                'status' => 'active',
            ],
        );

        $client->loginUser(new CustomerUser(self::ADMIN_ID, self::ADMIN_EMAIL, null, 'ROLE_SUPER_ADMIN', 'active'));

        return $client;
    }

    /** @return array<string, mixed> */
    private function payload(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'Produit à prix décimal',
            'brand' => 'Sopal',
            'ref' => 'DEC-001',
            'category' => 'sanitaires',
            'sub' => 'WC',
            'price' => 10,
            'oldPrice' => null,
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image' => '/p-wc.jpg',
            'isNew' => false,
            'best' => false,
            'headrest' => false,
            'description' => 'Produit créé par le test des prix décimaux.',
            'variants' => [],
        ];
    }

    private function slug(string $prefix): string
    {
        $slug = $prefix.'-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->slugs[] = $slug;

        return $slug;
    }

    private function storedMinor(string $slug, string $column): int
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne(
            sprintf('SELECT %s FROM storefront_product_view WHERE slug = :slug', $column),
            ['slug' => $slug],
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
