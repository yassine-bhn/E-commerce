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

/**
 * spec-run-16 WP3 (R3–R8, R17) — the admin write path owns the optional supplier reference.
 *
 * `supplierReference` mirrors `ref`'s typed contract (string or null, trimmed, 64 characters max)
 * but stays optional: absent, null, empty and whitespace-only all persist SQL NULL. PUT keeps its
 * full-replacement semantics, so an omitted key clears a stored value.
 */
final class AdminProductSupplierReferenceWriteTest extends WebTestCase
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

    public function testCreationPersistsTheTrimmedSupplierReferenceAndReturnsIt(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('supplier-create-trim');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'supplierReference' => '  SUP-42  ',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertArrayHasKey('supplierReference', $this->json($client));
        self::assertSame('SUP-42', $this->json($client)['supplierReference']);
        self::assertSame('SUP-42', $this->storedValue($slug));
    }

    public function testCreationWithoutTheKeyStoresNull(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('supplier-create-absent');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertArrayHasKey('supplierReference', $this->json($client));
        self::assertNull($this->json($client)['supplierReference']);
        self::assertNull($this->storedValue($slug));
    }

    /** @return iterable<string, array{mixed}> */
    public static function blankSupplierReferences(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
    }

    #[DataProvider('blankSupplierReferences')]
    public function testCreationNormalisesBlankSupplierReferencesToNull(mixed $blank): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('supplier-create-blank');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'supplierReference' => $blank,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->json($client)['supplierReference']);
        self::assertNull($this->storedValue($slug));
    }

    public function testCreationAcceptsExactlySixtyFourCharacters(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('supplier-create-limit');
        $value = str_repeat('A', 64);

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'supplierReference' => $value,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($value, $this->json($client)['supplierReference']);
        self::assertSame($value, $this->storedValue($slug));
    }

    public function testUpdateReplacesTheSupplierReferenceWithTheTrimmedValue(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'supplier-update-trim');
        $this->seedValue($slug, 'SUP-OLD');

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'supplierReference' => '  SUP-42  ',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('SUP-42', $this->json($client)['supplierReference']);
        self::assertSame('SUP-42', $this->storedValue($slug));
    }

    public function testUpdateWithoutTheKeyClearsTheSupplierReference(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'supplier-update-absent');
        $this->seedValue($slug, 'SUP-OLD');

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug));

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('supplierReference', $this->json($client));
        self::assertNull($this->json($client)['supplierReference']);
        self::assertNull($this->storedValue($slug), 'PUT is a full replacement: an omitted key clears');
    }

    #[DataProvider('blankSupplierReferences')]
    public function testUpdateNormalisesBlankSupplierReferencesToNull(mixed $blank): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'supplier-update-blank');
        $this->seedValue($slug, 'SUP-OLD');

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'supplierReference' => $blank,
        ]));

        self::assertResponseIsSuccessful();
        self::assertNull($this->json($client)['supplierReference']);
        self::assertNull($this->storedValue($slug));
    }

    public function testOverlongSupplierReferenceIsRejectedOnCreationWithoutWriting(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('supplier-create-overlong');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'supplierReference' => str_repeat('A', 65),
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->rowCount($slug));
    }

    public function testOverlongSupplierReferenceIsRejectedOnUpdateWithoutWriting(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'supplier-update-overlong');
        $this->seedValue($slug, 'SUP-1');

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'supplierReference' => str_repeat('A', 65),
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('SUP-1', $this->storedValue($slug));
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonStringSupplierReferences(): iterable
    {
        yield 'integer' => [123];
        yield 'float' => [12.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'list' => [['SUP-1']];
        yield 'map' => [['value' => 'SUP-1']];
    }

    #[DataProvider('nonStringSupplierReferences')]
    public function testNonStringSupplierReferenceIsRejectedOnCreationWithoutWriting(mixed $nonString): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->trackSlug('supplier-create-type');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'supplierReference' => $nonString,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->rowCount($slug), 'a rejected payload must not create a row');
    }

    #[DataProvider('nonStringSupplierReferences')]
    public function testNonStringSupplierReferenceIsRejectedOnUpdateWithoutWriting(mixed $nonString): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $slug = $this->createProduct($client, 'supplier-update-type');
        $this->seedValue($slug, 'SUP-1');

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'supplierReference' => $nonString,
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('SUP-1', $this->storedValue($slug), 'a rejected payload must not change the row');
    }

    public function testAStandardAdminCanWriteTheSupplierReferenceOnTheirOwnProduct(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('ROLE_ADMIN');
        $client->loginUser($admin);
        $slug = $this->trackSlug('supplier-admin-own');

        $client->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, [
            'supplierReference' => 'SUP-ADMIN',
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('SUP-ADMIN', $this->storedValue($slug));

        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'supplierReference' => 'SUP-ADMIN-2',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame('SUP-ADMIN-2', $this->storedValue($slug));

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertSame('SUP-ADMIN-2', $this->json($client)['supplierReference']);
    }

    public function testASuperAdminCanWriteTheSupplierReferenceOnAStandardAdminsProduct(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('ROLE_ADMIN'));
        $slug = $this->createProduct($client, 'supplier-super-other');

        $client->loginUser($this->createUser('ROLE_SUPER_ADMIN'));
        $client->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, [
            'supplierReference' => 'SUP-SUPER',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('SUP-SUPER', $this->storedValue($slug));
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
            'name' => 'Produit référence fournisseur',
            'brand' => 'Sopal',
            'ref' => 'TST-SUPPLIER',
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
            'description' => 'Produit créé par le test de référence fournisseur.',
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

    private function seedValue(string $slug, string $value): void
    {
        $this->connection()->executeStatement(
            'UPDATE storefront_product_view SET supplier_reference = :value WHERE slug = :slug',
            ['value' => $value, 'slug' => $slug],
        );
    }

    private function storedValue(string $slug): ?string
    {
        $value = $this->connection()->fetchOne(
            'SELECT supplier_reference FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );

        return false === $value || null === $value ? null : (string) $value;
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
        $email = 'admin.supplier.'.substr($id, 0, 8).'@example.com';
        $this->userIds[] = $id;

        $this->connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Admin Supplier Test',
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
