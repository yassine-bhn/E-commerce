<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * R4/R5 — `GET /api/v1/storefront/products` and `/search` answer the documented
 * `{items, meta, facets}` envelope, honour the filters and clamp the page parameters.
 *
 * The suite reindexes `storefront_product_view` first so Meilisearch serves exactly the rows
 * of the test database (one Meilisearch instance is shared by every APP_ENV in this repo).
 */
final class StorefrontCatalogBrowseTest extends WebTestCase
{
    private const FACET_KEYS = ['brand', 'category_slug', 'subcategory', 'stock', 'material'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container = static::getContainer();
        $indexer = $container->get(StorefrontProductSearchIndexer::class);
        if (!$indexer instanceof StorefrontProductSearchIndexer) {
            self::fail('The storefront indexer service is not available.');
        }

        try {
            $indexer->rebuildAll();
        } catch (\Throwable $exception) {
            self::markTestSkipped('Meilisearch is not reachable: '.$exception->getMessage());
        }
    }

    public function testTheFirstPageCarriesTheOffsetEnvelopeAndFacets(): void
    {
        $client = $this->client;
        $client->request('GET', '/api/v1/storefront/products');

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json($client);

        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('meta', $data);
        self::assertArrayHasKey('facets', $data);

        self::assertSame(1, $data['meta']['page']);
        self::assertSame(24, $data['meta']['size'], 'the browse default size is 24');

        // The storefront catalog is never assumed to hold a fixed number of rows: another
        // Functional test may have created a product for the length of its own test, and
        // Infection runs this suite in a random order. `meta.total` must equal the visible
        // catalog, whatever its size, and the envelope must be derived from that number.
        $total = self::storefrontProductTotal();
        self::assertSame($total, $data['meta']['total'], 'meta.total counts every visible product');
        self::assertSame((int) ceil($total / 24), $data['meta']['totalPages']);
        self::assertSame($total > 24, $data['meta']['hasMore']);
        self::assertCount(min(24, $total), $data['items']);

        /** @var array<string, mixed> $facets */
        $facets = $data['facets'];
        self::assertSame(self::FACET_KEYS, array_keys($facets), 'the documented facet set is returned');
    }

    public function testASecondPageWithACustomSizeDoesNotRepeatTheFirstOne(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/storefront/products?page=1&size=5');
        self::assertResponseIsSuccessful();
        $first = $this->json($client);

        $client->request('GET', '/api/v1/storefront/products?page=2&size=5');
        self::assertResponseIsSuccessful();
        $second = $this->json($client);

        $total = self::storefrontProductTotal();
        self::assertSame(2, $second['meta']['page']);
        self::assertSame(5, $second['meta']['size']);
        self::assertSame($total, $second['meta']['total'], 'meta.total is the filtered total, not the page size');
        self::assertSame((int) ceil($total / 5), $second['meta']['totalPages']);
        self::assertSame($total > 10, $second['meta']['hasMore']);

        $firstSlugs = array_column($first['items'], 'slug');
        $secondSlugs = array_column($second['items'], 'slug');

        self::assertCount(5, $firstSlugs);
        self::assertCount(max(0, min(5, $total - 5)), $secondSlugs);
        self::assertSame([], array_intersect($firstSlugs, $secondSlugs), 'page 2 must not repeat page 1');
    }

    public function testOutOfRangePageParametersAreClamped(): void
    {
        $client = $this->client;
        $client->request('GET', '/api/v1/storefront/products?page=0&size=5000');

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json($client);

        self::assertSame(1, $data['meta']['page']);
        self::assertSame(100, $data['meta']['size'], 'size is clamped to the documented maximum');
    }

    public function testFiltersAreCombinedAndFacetsCoverTheWholeFilteredSet(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/storefront/products?category=sanitaires');
        self::assertResponseIsSuccessful();
        $filtered = $this->json($client);

        self::assertGreaterThan(0, $filtered['meta']['total']);
        foreach ($filtered['items'] as $item) {
            self::assertSame('sanitaires', $item['category']);
        }

        self::assertSame(
            $filtered['meta']['total'],
            $filtered['facets']['category_slug']['sanitaires'] ?? null,
            'the facet count is computed over the whole filtered set, not the returned page',
        );

        $client->request('GET', '/api/v1/storefront/products?category=sanitaires&brand=Duravit');
        self::assertResponseIsSuccessful();
        $combined = $this->json($client);

        self::assertGreaterThan(0, $combined['meta']['total']);
        foreach ($combined['items'] as $item) {
            self::assertSame('sanitaires', $item['category']);
            self::assertSame('Duravit', $item['brand']);
        }
        self::assertLessThanOrEqual($filtered['meta']['total'], $combined['meta']['total']);
    }

    public function testPriceSortIsHonoured(): void
    {
        $client = $this->client;
        $client->request('GET', '/api/v1/storefront/products?sort=price_asc&size=100');

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json($client);

        $prices = array_column($data['items'], 'price');
        $sorted = $prices;
        sort($sorted);

        self::assertSame($sorted, $prices);
    }

    public function testAnUnknownSortFallsBackToTheDocumentedDefault(): void
    {
        $client = $this->client;
        $client->request('GET', '/api/v1/storefront/products?sort=created_at');

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json($client);

        $names = array_map(strval(...), array_column($data['items'], 'name'));
        $sorted = $names;
        $collator = new \Collator('fr_FR');
        usort($sorted, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        self::assertSame($sorted, $names, 'an invalid sort keeps the name ordering');
    }

    public function testPromotionsFilterIsServedWithTheSqlFallback(): void
    {
        $client = $this->client;
        $client->request('GET', '/api/v1/storefront/products?promotions=true');

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json($client);

        self::assertNull($data['facets'], 'the SQL fallback has no facet distribution');
        foreach ($data['items'] as $item) {
            self::assertNotNull($item['oldPrice'], 'promotions=true only returns discounted products');
        }
    }

    public function testSearchRequiresQAndAppliesTheSameEnvelope(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/storefront/products/search?q=baignoire');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json($client);

        self::assertSame('baignoire', $data['query']);
        self::assertGreaterThan(0, $data['meta']['total']);
        self::assertArrayHasKey('facets', $data);

        $client->request('GET', '/api/v1/storefront/products/search');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testASearchWithoutMatchesIsAValidEmptyAnswer(): void
    {
        $client = $this->client;
        $client->request('GET', '/api/v1/storefront/products/search?q=zzzz-not-a-product');

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = $this->json($client);

        self::assertSame([], $data['items']);
        self::assertSame(0, $data['meta']['total']);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The catalog the endpoint must report, read from the projection it serves. */
    private static function storefrontProductTotal(): int
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne('SELECT count(*) FROM storefront_product_view');
    }
}
