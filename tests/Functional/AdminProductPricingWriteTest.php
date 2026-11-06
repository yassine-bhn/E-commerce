<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminProductPricingWriteTest extends WebTestCase
{
    /** @var list<string> */
    private array $productSlugs = [];
    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        $connection = $this->connection();

        if ([] !== $this->productSlugs) {
            $connection->executeStatement(
                'DELETE FROM inventory_stock WHERE product_slug IN (:v)',
                ['v' => $this->productSlugs],
                ['v' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:v)',
                ['v' => $this->productSlugs],
                ['v' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM catalog_submission WHERE kind = :kind AND slug IN (:v)',
                ['kind' => 'products', 'v' => $this->productSlugs],
                ['kind' => ParameterType::STRING, 'v' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM catalog_audit_log WHERE target_type = :type AND target_slug IN (:v)',
                ['type' => 'product', 'v' => $this->productSlugs],
                ['type' => ParameterType::STRING, 'v' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->userIds) {
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:v)',
                ['v' => $this->userIds],
                ['v' => ArrayParameterType::STRING],
            );
        }

        parent::tearDown();
    }

    public function testCreationPersistsVatRateAndSalePriceTtcAndReturnsThem(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('pricing-create');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'vatRate' => '19.5',
            'salePriceTtc' => '  12.345  ',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json($client);
        self::assertSame(19.5, $body['vatRate']);
        self::assertSame(12345, $body['salePriceTtc']);
        self::assertSame(19.5, $this->storedVatRate($slug));
        self::assertSame(12345, $this->storedSalePriceTtc($slug));
    }

    public function testCreationAcceptsNumericVatRateAndIntegerSalePrice(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('pricing-create-numeric');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'vatRate' => 7.25,
            'salePriceTtc' => 5,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json($client);
        self::assertSame(7.25, $body['vatRate']);
        self::assertSame(5000, $body['salePriceTtc']);
        self::assertSame(7.25, $this->storedVatRate($slug));
        self::assertSame(5000, $this->storedSalePriceTtc($slug));
    }

    public function testCreationWithoutTheKeysStoresNull(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('pricing-create-absent');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json($client);
        self::assertArrayHasKey('vatRate', $body);
        self::assertNull($body['vatRate']);
        self::assertArrayHasKey('salePriceTtc', $body);
        self::assertNull($body['salePriceTtc']);
        self::assertNull($this->storedVatRate($slug));
        self::assertNull($this->storedSalePriceTtc($slug));
    }

    /** @return iterable<string, array{mixed}> */
    public static function blankValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
    }

    #[DataProvider('blankValues')]
    public function testCreationNormalisesBlankVatRateToNull(mixed $blank): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('pricing-create-vat-blank');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'vatRate' => $blank,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->json($client)['vatRate']);
        self::assertNull($this->storedVatRate($slug));
    }

    #[DataProvider('blankValues')]
    public function testCreationNormalisesBlankSalePriceTtcToNull(mixed $blank): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('pricing-create-sale-blank');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'salePriceTtc' => $blank,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->json($client)['salePriceTtc']);
        self::assertNull($this->storedSalePriceTtc($slug));
    }

    public function testUpdateReplacesVatRateAndSalePriceTtc(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'pricing-update');

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'vatRate' => '20.5',
            'salePriceTtc' => '99.999',
        ]));

        self::assertResponseIsSuccessful();
        $body = $this->json($client);
        self::assertSame(20.5, $body['vatRate']);
        self::assertSame(99999, $body['salePriceTtc']);
        self::assertSame(20.5, $this->storedVatRate($slug));
        self::assertSame(99999, $this->storedSalePriceTtc($slug));
    }

    public function testUpdateWithoutTheKeysClearsBothValues(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'pricing-update-clear');
        $this->seedValues($slug, 19.5, 12345);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug));

        self::assertResponseIsSuccessful();
        $body = $this->json($client);
        self::assertArrayHasKey('vatRate', $body);
        self::assertNull($body['vatRate']);
        self::assertArrayHasKey('salePriceTtc', $body);
        self::assertNull($body['salePriceTtc']);
        self::assertNull($this->storedVatRate($slug));
        self::assertNull($this->storedSalePriceTtc($slug));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidVatRates(): iterable
    {
        yield 'malformed' => ['abc'];
        yield 'three decimals' => ['19.555'];
        yield 'integer-as-string with trailing dot' => ['19.'];
        yield 'boolean' => [true];
        yield 'list' => [[19.5]];
        yield 'map' => [['value' => 19.5]];
    }

    #[DataProvider('invalidVatRates')]
    public function testInvalidVatRateIsRejectedOnCreationWithoutWriting(mixed $invalid): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('pricing-vat-invalid-create');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'vatRate' => $invalid,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->rowCount($slug));
    }

    #[DataProvider('invalidVatRates')]
    public function testInvalidVatRateIsRejectedOnUpdateWithoutWriting(mixed $invalid): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'pricing-vat-invalid-update');
        $this->seedValues($slug, 19.5, 12345);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'vatRate' => $invalid,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(19.5, $this->storedVatRate($slug));
        self::assertSame(12345, $this->storedSalePriceTtc($slug));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSalePrices(): iterable
    {
        yield 'malformed' => ['abc'];
        yield 'negative' => ['-1.5'];
        yield 'four decimals' => ['1.2345'];
        yield 'boolean' => [true];
        yield 'list' => [[1.5]];
        yield 'map' => [['value' => 1.5]];
    }

    #[DataProvider('invalidSalePrices')]
    public function testInvalidSalePriceTtcIsRejectedOnCreationWithoutWriting(mixed $invalid): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('pricing-sale-invalid-create');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'salePriceTtc' => $invalid,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->rowCount($slug));
    }

    #[DataProvider('invalidSalePrices')]
    public function testInvalidSalePriceTtcIsRejectedOnUpdateWithoutWriting(mixed $invalid): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'pricing-sale-invalid-update');
        $this->seedValues($slug, 19.5, 12345);

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'salePriceTtc' => $invalid,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(19.5, $this->storedVatRate($slug));
        self::assertSame(12345, $this->storedSalePriceTtc($slug));
    }

    public function testPriceValidationIsUnchanged(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('pricing-price-unchanged');

        $payload = $this->payload($slug);
        $payload['price'] = '1.2345';

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $payload);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->rowCount($slug));
    }

    public function testAStandardAdminCanWriteThePricingFieldsOnTheirOwnProduct(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('ROLE_ADMIN');
        $client->loginUser($admin);
        $slug = $this->trackSlug('pricing-admin-own');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'vatRate' => '5.5',
            'salePriceTtc' => '10.000',
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(5.5, $this->storedVatRate($slug));
        self::assertSame(10000, $this->storedSalePriceTtc($slug));

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'vatRate' => '6.5',
            'salePriceTtc' => '11.000',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame(6.5, $this->storedVatRate($slug));
        self::assertSame(11000, $this->storedSalePriceTtc($slug));

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertSame(6.5, $this->json($client)['vatRate']);
        self::assertSame(11000, $this->json($client)['salePriceTtc']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(string $slug, array $overrides = []): array
    {
        return $overrides + [
            'slug' => $slug,
            'name' => 'Produit pricing',
            'brand' => 'Sopal',
            'ref' => 'TST-PRICING',
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
            'description' => 'Produit créé par le test pricing.',
            'variants' => [],
        ];
    }

    private function createProduct(KernelBrowser $client, string $prefix): string
    {
        $slug = $this->trackSlug($prefix);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $slug;
    }

    private function trackSlug(string $prefix): string
    {
        $slug = $prefix.'-'.substr(Uuid::v4()->toRfc4122(), 0, 8);
        $this->productSlugs[] = $slug;

        return $slug;
    }

    private function seedValues(string $slug, float $vatRate, int $salePriceTtc): void
    {
        $this->connection()->executeStatement(
            'UPDATE storefront_product_view SET vat_rate = :vat, sale_price_ttc_minor = :sale WHERE slug = :slug',
            ['vat' => $vatRate, 'sale' => $salePriceTtc, 'slug' => $slug],
        );
    }

    private function storedVatRate(string $slug): ?float
    {
        $value = $this->connection()->fetchOne(
            'SELECT vat_rate FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );

        return false === $value || null === $value ? null : (float) $value;
    }

    private function storedSalePriceTtc(string $slug): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT sale_price_ttc_minor FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );

        return false === $value || null === $value ? null : (int) $value;
    }

    private function rowCount(string $slug): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    private function createUser(string $role): CustomerUser
    {
        $id = Uuid::v4()->toRfc4122();
        $email = 'admin.pricing.'.substr($id, 0, 8).'@example.com';
        $this->userIds[] = $id;

        $this->connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Admin Pricing Test',
            'email' => $email,
            'role' => $role,
            'status' => 'active',
        ]);

        return new CustomerUser($id, $email, null, $role, 'active');
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
