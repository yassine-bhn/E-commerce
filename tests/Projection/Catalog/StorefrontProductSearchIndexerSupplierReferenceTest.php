<?php

declare(strict_types=1);

namespace App\Tests\Projection\Catalog;

use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-16 WP6 (R12) — the storefront search index never carries the supplier
 * reference: document keys stay supplier-free, and the three settings arrays are
 * unchanged.
 */
final class StorefrontProductSearchIndexerSupplierReferenceTest extends KernelTestCase
{
    private const SUPPLIER_VALUE = 'SUP-SECRET-42';

    private ?Connection $connection = null;

    /** @var list<string> */
    private array $seededSlugs = [];
    /** @var list<string> */
    private array $seededCategorySlugs = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        if (!$connection instanceof Connection) {
            self::fail('Doctrine DBAL Connection service is not available.');
        }

        try {
            $connection->executeQuery('SELECT 1 FROM storefront_product_view LIMIT 1');
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
            foreach ($this->seededCategorySlugs as $slug) {
                $connection->executeStatement('DELETE FROM storefront_category WHERE slug = :slug', ['slug' => $slug]);
            }
        }

        $this->seededSlugs = [];
        $this->seededCategorySlugs = [];
        $this->connection = null;

        parent::tearDown();
    }

    public function testEnsureSettingsKeepsTheThreeAttributeArraysUnchanged(): void
    {
        $http = new RecordingHttpClient();
        $indexer = new StorefrontProductSearchIndexer($this->connection(), new Client('http://meilisearch:7700', null, $http));

        $indexer->ensureSettings();

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['name', 'brand', 'ref', 'subcategory', 'category_slug', 'material'], $payload['searchableAttributes']);
        self::assertSame(
            ['category_slug', 'subcategory', 'brand', 'is_new', 'is_best', 'price_minor', 'stock', 'material', 'rating'],
            $payload['filterableAttributes'],
        );
        self::assertSame(['price_minor', 'rating', 'reviews', 'name'], $payload['sortableAttributes']);
    }

    public function testIndexSlugDocumentHasNoSupplierKeyOrValue(): void
    {
        $slug = 'spec-indexer-supplier-'.Uuid::v4()->toRfc4122();
        $this->seedProduct($slug, self::SUPPLIER_VALUE);

        $http = new RecordingHttpClient();
        $indexer = new StorefrontProductSearchIndexer($this->connection(), new Client('http://meilisearch:7700', null, $http));

        $indexer->indexSlug($slug);

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request['method']);

        /** @var list<array<string, mixed>> $documents */
        $documents = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $documents);
        $document = $documents[0];

        foreach (['supplierReference', 'supplier_reference', 'supplier-reference', 'supplierRefference'] as $key) {
            self::assertArrayNotHasKey($key, $document);
        }
        self::assertStringNotContainsString(self::SUPPLIER_VALUE, (string) json_encode($document));

        // Exact key set unchanged (no supplier key added).
        self::assertSame(
            ['id', 'slug', 'name', 'brand', 'ref', 'category_slug', 'subcategory', 'price_minor',
                'old_price_minor', 'rating', 'reviews', 'is_new', 'is_best', 'stock', 'material', 'dims', 'image_path'],
            array_keys($document),
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

    private function seedProduct(string $slug, ?string $supplierReference = null): void
    {
        $this->connection()->insert('storefront_product_view', [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => $slug,
            'name' => 'Indexer Fixture',
            'brand' => 'Zidane',
            'ref' => 'ZID-001',
            'supplier_reference' => $supplierReference,
            'category_slug' => 'sanitaires',
            'subcategory' => 'Chaises de bureau',
            'price_minor' => 250000,
            'old_price_minor' => 290000,
            'currency' => 'TND',
            'stock' => 'in',
            'material' => 'Acier',
            'dims' => '60x60x100',
            'image_path' => '/uploads/products/fixture.png',
            'rating' => 4.5,
            'reviews' => 12,
            'is_new' => true,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Fixture product',
            'variants' => '[]',
            'approval_status' => 'APPROVED',
            'published' => true,
        ], [
            'price_minor' => ParameterType::INTEGER,
            'old_price_minor' => ParameterType::INTEGER,
            'rating' => ParameterType::STRING,
            'reviews' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
            'published' => ParameterType::BOOLEAN,
        ]);

        $this->seededSlugs[] = $slug;
    }
}
