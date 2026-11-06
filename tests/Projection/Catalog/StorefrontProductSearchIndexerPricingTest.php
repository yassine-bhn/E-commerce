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
 * spec-run-17 WP6 (R13) — the storefront search index never carries the VAT rate or
 * sale price TTC: document keys stay pricing-free, and the three settings arrays are
 * unchanged.
 */
final class StorefrontProductSearchIndexerPricingTest extends KernelTestCase
{
    private const VAT_RATE_VALUE = 12.34;
    private const SALE_PRICE_TTC_VALUE = 777777;

    private ?Connection $connection = null;

    /** @var list<string> */
    private array $seededSlugs = [];

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
        }

        $this->seededSlugs = [];
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

    public function testIndexSlugDocumentHasNoPricingKeyOrValue(): void
    {
        $slug = 'spec-indexer-pricing-'.Uuid::v4()->toRfc4122();
        $this->seedProduct($slug);

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

        foreach (['vatRate', 'vat_rate', 'salePriceTtc', 'sale_price_ttc_minor'] as $key) {
            self::assertArrayNotHasKey($key, $document);
        }
        $encoded = (string) json_encode($document);
        self::assertStringNotContainsString('12.34', $encoded);
        self::assertStringNotContainsString('777777', $encoded);

        // Exact key set unchanged (no pricing key added).
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

    private function seedProduct(string $slug): void
    {
        $this->connection()->insert('storefront_product_view', [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => $slug,
            'name' => 'Indexer Fixture',
            'brand' => 'Zidane',
            'ref' => 'ZID-001',
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
            'vat_rate' => self::VAT_RATE_VALUE,
            'sale_price_ttc_minor' => self::SALE_PRICE_TTC_VALUE,
        ], [
            'price_minor' => ParameterType::INTEGER,
            'old_price_minor' => ParameterType::INTEGER,
            'rating' => ParameterType::STRING,
            'reviews' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
            'published' => ParameterType::BOOLEAN,
            'vat_rate' => ParameterType::STRING,
            'sale_price_ttc_minor' => ParameterType::INTEGER,
        ]);

        $this->seededSlugs[] = $slug;
    }
}
