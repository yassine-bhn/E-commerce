<?php

declare(strict_types=1);

namespace App\Tests\Projection\Catalog;

use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Meilisearch\Client;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ECO-008 / PRF-003 — the storefront search index is written from `storefront_product_view`
 * with the documented settings, and a Meilisearch outage never breaks the caller.
 */
final class StorefrontProductSearchIndexerTest extends KernelTestCase
{
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

    public function testEnsureSettingsSendsTheDocumentedAttributes(): void
    {
        $http = new RecordingHttpClient();
        $indexer = new StorefrontProductSearchIndexer($this->connection(), new Client('http://meilisearch:7700', null, $http));

        $indexer->ensureSettings();

        self::assertCount(1, $http->requests, 'settings must be pushed with a single request');
        $request = $http->requests[0];
        self::assertSame('PATCH', $request['method']);
        self::assertSame('http://meilisearch:7700/indexes/storefront_products/settings', $request['uri']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['name', 'brand', 'ref', 'subcategory', 'category_slug', 'material'], $payload['searchableAttributes']);
        self::assertSame(
            ['category_slug', 'subcategory', 'brand', 'is_new', 'is_best', 'price_minor', 'stock', 'material', 'rating'],
            $payload['filterableAttributes'],
        );
        self::assertSame(['price_minor', 'rating', 'reviews', 'name'], $payload['sortableAttributes']);
        self::assertSame(5000, $payload['pagination']['maxTotalHits']);
    }

    public function testIndexSlugSendsTheFullStorefrontDocument(): void
    {
        $slug = 'spec-indexer-'.Uuid::v4()->toRfc4122();
        $this->seedProduct($slug);

        $http = new RecordingHttpClient();
        $indexer = new StorefrontProductSearchIndexer($this->connection(), new Client('http://meilisearch:7700', null, $http));

        $indexer->indexSlug($slug);

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertStringContainsString('/indexes/storefront_products/documents', $request['uri']);

        /** @var list<array<string, mixed>> $documents */
        $documents = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $documents);
        $document = $documents[0];
        self::assertSame($slug, $document['id'], 'the primary key is the slug');
        self::assertSame(
            [
                'id', 'slug', 'name', 'brand', 'ref', 'category_slug', 'subcategory', 'price_minor',
                'old_price_minor', 'rating', 'reviews', 'is_new', 'is_best', 'stock', 'material', 'dims', 'image_path',
            ],
            array_keys($document),
        );
        self::assertSame('Indexer Fixture', $document['name']);
        self::assertSame('chaises', $document['category_slug']);
        self::assertSame(250000, $document['price_minor']);
        self::assertSame(4.5, $document['rating']);
        self::assertTrue($document['is_new']);
        self::assertSame('in', $document['stock']);
    }

    public function testIndexSlugOfAnUnknownProductRemovesTheDocument(): void
    {
        $http = new RecordingHttpClient();
        $indexer = new StorefrontProductSearchIndexer($this->connection(), new Client('http://meilisearch:7700', null, $http));

        $indexer->indexSlug('missing-'.Uuid::v4()->toRfc4122());

        self::assertCount(1, $http->requests);
        self::assertSame('DELETE', $http->requests[0]['method']);
        self::assertStringContainsString('/indexes/storefront_products/documents/', $http->requests[0]['uri']);
    }

    public function testSyncToleratesAnUnreachableMeilisearch(): void
    {
        $slug = 'spec-indexer-'.Uuid::v4()->toRfc4122();
        $this->seedProduct($slug);

        $indexer = new StorefrontProductSearchIndexer(
            $this->connection(),
            new Client('http://meilisearch:7700', null, new RecordingHttpClient(fail: true)),
        );

        try {
            $indexer->sync($slug);
            $indexer->forget($slug);
        } catch (\Throwable $exception) {
            self::fail('PRF-003: a search outage must never break a catalog write, got: '.$exception->getMessage());
        }

        self::addToAssertionCount(1); // reaching here means both best-effort calls returned
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
            'category_slug' => 'chaises',
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
        ], [
            'price_minor' => ParameterType::INTEGER,
            'old_price_minor' => ParameterType::INTEGER,
            'rating' => ParameterType::STRING,
            'reviews' => ParameterType::INTEGER,
            'is_new' => ParameterType::BOOLEAN,
            'is_best' => ParameterType::BOOLEAN,
            'headrest' => ParameterType::BOOLEAN,
        ]);

        $this->seededSlugs[] = $slug;
    }
}

/**
 * Records every PSR-18 request the Meilisearch client emits, so the indexer contract
 * (method, path, payload) is asserted without a live server.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<array{method: string, uri: string, body: string}> */
    public array $requests = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->fail) {
            throw new \RuntimeException('Meilisearch is unreachable.');
        }

        $this->requests[] = [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'body' => (string) $request->getBody(),
        ];

        return new Response(202, ['Content-Type' => 'application/json'], json_encode([
            'taskUid' => 1,
            'indexUid' => 'storefront_products',
            'status' => 'enqueued',
            'type' => 'documentAdditionOrUpdate',
            'enqueuedAt' => '2026-10-22T00:00:00Z',
        ], JSON_THROW_ON_ERROR));
    }
}
