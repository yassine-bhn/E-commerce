<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Query\StorefrontProductFilters;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductPage;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductRepository;
use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use App\Shared\Application\Pagination\PageRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Meilisearch\Client;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * R4/R5 — when Meilisearch cannot serve a request the catalog answers from SQL: the same
 * filters, the same ordering and the same `meta`, with `facets = null` (ADR-0009).
 *
 * The repository is built with a client pointing at a closed port so every call fails at
 * the transport, which is exactly the outage the fallback exists for.
 */
final class StorefrontProductSqlFallbackTest extends KernelTestCase
{
    private const string CATEGORY = 'it-fallback';

    private static ?string $brand = null;

    /** @var list<string> */
    private array $slugs = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::connection();
        $connection->executeStatement('DELETE FROM storefront_product_view WHERE category_slug = :category', ['category' => self::CATEGORY]);
        // spec-run-8 R31 — a product is only reachable through an approved, visible category,
        // so the fixture owns the category its products point at.
        $connection->executeStatement('DELETE FROM storefront_category WHERE slug = :category', ['category' => self::CATEGORY]);
        $connection->insert('storefront_category', [
            'slug' => self::CATEGORY,
            'label' => 'IT fallback',
            'subcategories' => '[]',
            'quote_eligible' => false,
            'hidden' => false,
            'sort_order' => 999,
            'parent_slug' => null,
            'approval_status' => 'APPROVED',
        ], [
            'quote_eligible' => ParameterType::BOOLEAN,
            'hidden' => ParameterType::BOOLEAN,
        ]);

        self::$brand ??= 'FallbackBrand'.substr(bin2hex(random_bytes(3)), 0, 4);

        $this->insertProduct('alpha', 'Alpha Chauffe-eau', 'Chauffe-eau', 10_000, 12_000, 'in', 'Acier', 4.5, 12, true, false);
        $this->insertProduct('beta', 'Beta Mitigeur', 'Mitigeur', 30_000, null, 'out', 'Laiton', 3.0, 4, false, true);
        $this->insertProduct('gamma', 'Gamma Lavabo', 'Lavabo', 20_000, null, 'in', 'Acier', 5.0, 30, true, true);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->slugs) {
            self::connection()->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
                ['slugs' => $this->slugs],
                ['slugs' => ArrayParameterType::STRING],
            );
        }
        $this->slugs = [];

        self::connection()->executeStatement('DELETE FROM storefront_category WHERE slug = :category', ['category' => self::CATEGORY]);

        parent::tearDown();
    }

    public function testAnUnreachableIndexFallsBackToSqlWithoutFacets(): void
    {
        $page = $this->repository()->browse(
            new StorefrontProductFilters(categorySlug: self::CATEGORY),
            new PageRequest(1, 24),
        );

        self::assertNull($page->facets, 'the SQL fallback has no facet distribution');
        self::assertSame(3, $page->page->total);
        self::assertSame(['Alpha Chauffe-eau', 'Beta Mitigeur', 'Gamma Lavabo'], $this->names($page));
        foreach ($page->page->items as $item) {
            self::assertInstanceOf(StorefrontProductView::class, $item);
            self::assertSame(self::CATEGORY, $item->categorySlug);
        }
    }

    public function testTheSqlFallbackCombinesEveryFilterAndKeepsTheRequestedSort(): void
    {
        $filtered = $this->repository()->browse(
            new StorefrontProductFilters(
                categorySlug: self::CATEGORY,
                isNew: true,
                material: 'Acier',
                priceMin: 15_000,
                priceMax: 25_000,
                stock: 'in',
            ),
            new PageRequest(1, 24),
        );

        self::assertNull($filtered->facets);
        self::assertSame(['Gamma Lavabo'], $this->names($filtered), 'all filters are ANDed on the SQL path');

        $descending = $this->repository()->browse(
            new StorefrontProductFilters(categorySlug: self::CATEGORY, sort: 'price_desc'),
            new PageRequest(1, 24),
        );

        self::assertSame(['Beta Mitigeur', 'Gamma Lavabo', 'Alpha Chauffe-eau'], $this->names($descending));
    }

    public function testTheSqlFallbackPaginatesWithoutRepeatingRows(): void
    {
        $first = $this->repository()->browse(
            new StorefrontProductFilters(categorySlug: self::CATEGORY),
            new PageRequest(1, 2),
        );
        $second = $this->repository()->browse(
            new StorefrontProductFilters(categorySlug: self::CATEGORY),
            new PageRequest(2, 2),
        );

        self::assertSame(3, $first->page->total);
        self::assertSame(2, $first->page->totalPages());
        self::assertTrue($first->page->hasMore());
        self::assertFalse($second->page->hasMore());

        $firstSlugs = array_map(static fn (StorefrontProductView $view): string => $view->slug, $first->page->items);
        $secondSlugs = array_map(static fn (StorefrontProductView $view): string => $view->slug, $second->page->items);

        self::assertCount(2, $firstSlugs);
        self::assertCount(1, $secondSlugs);
        self::assertSame([], array_intersect($firstSlugs, $secondSlugs));
    }

    public function testPromotionsAndNewestAreAlwaysServedBySql(): void
    {
        $promotions = $this->repository()->browse(
            new StorefrontProductFilters(categorySlug: self::CATEGORY, promotionsOnly: true),
            new PageRequest(1, 24),
        );

        self::assertNull($promotions->facets, 'promotions cannot be expressed on the index, so they never carry facets');
        self::assertSame(['Alpha Chauffe-eau'], $this->names($promotions));

        $newest = $this->repository()->browse(
            new StorefrontProductFilters(categorySlug: self::CATEGORY, sort: 'newest'),
            new PageRequest(1, 24),
        );

        self::assertNull($newest->facets, 'newest cannot be sorted on the index, so it never carries facets');
        self::assertSame(['Alpha Chauffe-eau', 'Gamma Lavabo', 'Beta Mitigeur'], $this->names($newest), 'is_new products come first');
    }

    public function testTheSqlSearchEscapesLikeWildcards(): void
    {
        $repository = $this->repository();

        $hit = $repository->search(
            new StorefrontProductFilters(q: 'chauffe', categorySlug: self::CATEGORY),
            new PageRequest(1, 24),
        );
        self::assertSame(['Alpha Chauffe-eau'], $this->names($hit));
        self::assertNull($hit->facets);

        $wildcards = $repository->search(
            new StorefrontProductFilters(q: '%', categorySlug: self::CATEGORY),
            new PageRequest(1, 24),
        );
        self::assertSame(0, $wildcards->page->total, 'a raw % must match nothing instead of everything');
        self::assertSame([], $this->names($wildcards));
    }

    private function repository(): StorefrontProductRepository
    {
        // Port 1 is closed: every call throws at the transport and the repository falls back to SQL.
        return new StorefrontProductRepository(self::connection(), new Client('http://127.0.0.1:1'));
    }

    public function testAQueryLessBrowseFallsBackToSqlWhenTheIndexReportsZeroHits(): void
    {
        $page = $this->zeroHitRepository()->browse(
            new StorefrontProductFilters(categorySlug: self::CATEGORY),
            new PageRequest(1, 24),
        );

        self::assertSame(3, $page->page->total, 'a cold or partial index must never blank the catalog');
        self::assertNull($page->facets, 'the SQL fallback served the request');
        self::assertSame(['Alpha Chauffe-eau', 'Beta Mitigeur', 'Gamma Lavabo'], $this->names($page));
    }

    public function testASearchWithZeroIndexHitsStaysEmptyInsteadOfFallingBack(): void
    {
        $page = $this->zeroHitRepository()->search(
            new StorefrontProductFilters(q: 'Alpha', categorySlug: self::CATEGORY),
            new PageRequest(1, 24),
        );

        self::assertSame(0, $page->page->total);
        self::assertSame([], $this->names($page), 'zero hits on a non-empty term is a valid answer, not an outage');
        self::assertNotNull($page->facets, 'the index answered, so facets are present');
    }

    private function zeroHitRepository(): StorefrontProductRepository
    {
        // The stub answers every search with 0 hits, like a cold or partially built index.
        return new StorefrontProductRepository(
            self::connection(),
            new Client('http://meilisearch:7700', null, new ZeroHitStorefrontIndexHttpClient()),
        );
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    private function insertProduct(
        string $suffix,
        string $name,
        string $subcategory,
        int $priceMinor,
        ?int $oldPriceMinor,
        string $stock,
        string $material,
        float $rating,
        int $reviews,
        bool $isNew,
        bool $isBest,
    ): void {
        $slug = sprintf('%s-%s-%s', self::CATEGORY, $suffix, substr(bin2hex(random_bytes(3)), 0, 6));

        self::connection()->insert(
            'storefront_product_view',
            [
                'id' => '01929312-fa11-7000-8000-'.substr(bin2hex(random_bytes(6)), 0, 12),
                'slug' => $slug,
                'name' => $name,
                'brand' => self::$brand,
                'ref' => 'IT-'.strtoupper($suffix),
                'category_slug' => self::CATEGORY,
                'subcategory' => $subcategory,
                'price_minor' => $priceMinor,
                'old_price_minor' => $oldPriceMinor,
                'currency' => 'TND',
                'stock' => $stock,
                'material' => $material,
                'dims' => '10 x 20 cm',
                'image_path' => '/uploads/products/it-fallback.jpg',
                'rating' => $rating,
                'reviews' => $reviews,
                'is_new' => $isNew,
                'is_best' => $isBest,
                'description' => $name.' description',
                // spec-run-8 R28 — these products exist to be browsed, so they are approved and published.
                'approval_status' => 'APPROVED',
                'published' => true,
            ],
            [
                'rating' => ParameterType::STRING,
                'is_new' => ParameterType::BOOLEAN,
                'is_best' => ParameterType::BOOLEAN,
                'reviews' => ParameterType::INTEGER,
                'published' => ParameterType::BOOLEAN,
                'price_minor' => ParameterType::INTEGER,
                'old_price_minor' => ParameterType::INTEGER,
            ],
        );

        $this->slugs[] = $slug;
    }

    /**
     * @return list<string>
     */
    private function names(StorefrontProductPage $page): array
    {
        return array_values(array_map(
            static fn (StorefrontProductView $view): string => $view->name,
            $page->page->items,
        ));
    }
}

/**
 * Answers every Meilisearch call with a valid search payload that carries no hit, so the
 * repository sees a reachable but empty (cold/partial) index instead of a transport error.
 */
final class ZeroHitStorefrontIndexHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'hits' => [],
            'query' => '',
            'processingTimeMs' => 0,
            'limit' => 24,
            'offset' => 0,
            'estimatedTotalHits' => 0,
        ], JSON_THROW_ON_ERROR));
    }
}
