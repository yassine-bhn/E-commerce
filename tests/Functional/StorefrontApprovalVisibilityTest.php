<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Meilisearch\Exceptions\CommunicationException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * R27/R29/R30/R51 — the approval state is enforced on every public surface, not only on the
 * detail route.
 *
 * Rows are seeded directly and the index is rebuilt after seeding, so each test starts from a known
 * projection. The SQL fallback is identified by its `facets: null` payload: a page served from the
 * index always carries the five facet keys, so a fallback assertion cannot pass while the index
 * served the request instead. Each test also carries a product the storefront *must* serve, so an
 * empty answer can never make a negative assertion pass.
 */
final class StorefrontApprovalVisibilityTest extends WebTestCase
{
    /** Deterministic per-test identity: a counter cannot collide the way a truncated clock can. */
    private static int $sequence = 0;

    /** @var list<string> */
    private array $productSlugs = [];
    /** @var list<string> */
    private array $categorySlugs = [];
    /** @var array<string, string> product slug => the single search token its row carries */
    private array $tokens = [];

    private ?Connection $connection = null;
    private ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        self::$sequence = 0;
        $this->client = static::createClient();

        // Proves the projection is reachable before a test spends its assertions on it; a suite run
        // without a reachable Meilisearch skips instead of reporting the storefront as broken. Only a
        // transport failure is tolerated: a rejected settings payload is an indexer defect, not an
        // outage, and has to red the test rather than silently skip it.
        try {
            $this->indexer()->ensureSettings();
        } catch (CommunicationException $exception) {
            self::markTestSkipped('Meilisearch is not reachable: '.$exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $connection = $this->connection;
        if ($connection instanceof Connection) {
            if ([] !== $this->productSlugs) {
                $connection->executeStatement(
                    'DELETE FROM storefront_product_review WHERE product_slug IN (:slugs)',
                    ['slugs' => $this->productSlugs],
                    ['slugs' => ArrayParameterType::STRING],
                );
                $connection->executeStatement(
                    'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
                    ['slugs' => $this->productSlugs],
                    ['slugs' => ArrayParameterType::STRING],
                );
            }
            if ([] !== $this->categorySlugs) {
                $connection->executeStatement(
                    'DELETE FROM storefront_category WHERE slug IN (:slugs)',
                    ['slugs' => $this->categorySlugs],
                    ['slugs' => ArrayParameterType::STRING],
                );
            }
        }

        // A deleted row must not survive as a document: a later test that reads the index without
        // rebuilding would otherwise count a product that no longer exists.
        foreach (array_merge($this->productSlugs, $this->categorySlugs) as $slug) {
            $this->indexer()->forget($slug);
        }

        $this->productSlugs = [];
        $this->categorySlugs = [];
        $this->tokens = [];
        $this->connection = null;
        $this->client = null;

        parent::tearDown();
    }

    /**
     * R27/R51 — a pending or rejected product is absent from the list, the search and the detail
     * route, whichever implementation serves the page, however its publication flag is set.
     */
    public function testAPendingOrRejectedProductIsAbsentFromEveryPublicRead(): void
    {
        $category = $this->seedCategory();
        $published = $this->seedProduct($category);
        $pending = $this->seedProduct($category, 'PENDING_APPROVAL');
        $rejected = $this->seedProduct($category, 'REJECTED');
        $this->rebuild();

        $client = $this->client();
        $client->request('GET', '/api/v1/storefront/products?category='.$category.'&size=100');
        self::assertResponseIsSuccessful();
        self::assertSame([$published], $this->slugs(), 'the index-backed list serves the approved product only');
        self::assertSame(1, $this->metaTotal(), 'the index count agrees with the items it returned');
        self::assertNotNull($this->json()['facets'], 'a page served from the index always carries its facets');

        // promotions=true is not indexable, so this page comes from the SQL fallback of the same
        // repository: the second implementation of the predicate must agree with the first.
        $client->request('GET', '/api/v1/storefront/products?category='.$category.'&promotions=true&size=100');
        self::assertResponseIsSuccessful();
        self::assertSame([$published], $this->slugs(), 'the SQL fallback applies the same visibility predicate');
        self::assertNull($this->json()['facets'], 'the SQL fallback served this page, so the assertion above is about it');
        self::assertSame(1, $this->metaTotal(), 'the fallback count agrees with the items it returned');

        $client->request('GET', '/api/v1/storefront/products/search?q='.$this->tokenOf($published).'&size=100');
        self::assertResponseIsSuccessful();
        self::assertSame([$published], $this->slugs());
        self::assertSame(1, $this->metaTotal());

        // Each hidden row is searched by its own words: a document that survived the index build
        // would answer here, and the count is asserted too because the index total is computed
        // before the visibility re-filter that cleans up `items`.
        $client->request('GET', '/api/v1/storefront/products/search?q='.$this->tokenOf($pending).'&size=100');
        self::assertSame([], $this->slugs(), 'a pending product is not searchable');
        self::assertSame(0, $this->metaTotal(), 'a pending product does not even contribute to the count');

        $client->request('GET', '/api/v1/storefront/products/search?q='.$this->tokenOf($rejected).'&size=100');
        self::assertSame([], $this->slugs(), 'a rejected product is not searchable');
        self::assertSame(0, $this->metaTotal(), 'a rejected product does not even contribute to the count');

        $client->request('GET', '/api/v1/storefront/products/'.$pending);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('GET', '/api/v1/storefront/products/'.$rejected);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('GET', '/api/v1/storefront/products/'.$published);
        self::assertResponseIsSuccessful();
    }

    /**
     * R27 — approval and publication are independent: flipping the `published` flag alone decides
     * whether an approved product reaches the storefront.
     */
    public function testAnApprovedButUnpublishedProductAppearsOnlyOncePublished(): void
    {
        $category = $this->seedCategory();
        $product = $this->seedProduct($category, 'APPROVED', false);
        $this->rebuild();

        $client = $this->client();
        $client->request('GET', '/api/v1/storefront/products?category='.$category.'&size=100');
        self::assertSame([], $this->slugs(), 'approved is not published');
        $client->request('GET', '/api/v1/storefront/products?category='.$category.'&promotions=true&size=100');
        self::assertSame([], $this->slugs(), 'the SQL fallback also refuses an unpublished product');
        self::assertNull($this->json()['facets']);
        $client->request('GET', '/api/v1/storefront/products/search?q='.$this->tokenOf($product));
        self::assertSame([], $this->slugs());
        self::assertSame(0, $this->metaTotal(), 'an unpublished product is not indexed either');
        $client->request('GET', '/api/v1/storefront/products/'.$product);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->db()->executeStatement(
            'UPDATE storefront_product_view SET published = true WHERE slug = :slug',
            ['slug' => $product],
        );
        $this->rebuild();

        $client->request('GET', '/api/v1/storefront/products?category='.$category.'&size=100');
        self::assertSame([$product], $this->slugs(), 'publishing an approved product is what makes it public');
        $client->request('GET', '/api/v1/storefront/products/search?q='.$this->tokenOf($product));
        self::assertSame([$product], $this->slugs());
        $client->request('GET', '/api/v1/storefront/products/'.$product);
        self::assertResponseIsSuccessful();
    }

    /**
     * R29/R30/R51 — an approved and published product stays private while its category awaits
     * approval, and that category is dropped from the taxonomy the frontend navigates by.
     */
    public function testAProductOfAnUnapprovedCategoryIsPrivateAndTheCategoryIsNotNavigable(): void
    {
        $approvedCategory = $this->seedCategory();
        $pendingCategory = $this->seedCategory('PENDING_APPROVAL');
        $navigable = $this->seedProduct($approvedCategory);
        $held = $this->seedProduct($pendingCategory);
        $this->rebuild();

        $client = $this->client();
        $client->request('GET', '/api/v1/storefront/products?category='.$pendingCategory.'&size=100');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->slugs(), 'the category gate is part of the product predicate');

        // The category clause is the one a refactor is most likely to drop from the SQL branch, so
        // it is asserted on both implementations.
        $client->request('GET', '/api/v1/storefront/products?category='.$pendingCategory.'&promotions=true&size=100');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->slugs(), 'the SQL fallback applies the category gate too');
        self::assertNull($this->json()['facets']);

        $client->request('GET', '/api/v1/storefront/products/search?q='.$this->tokenOf($held).'&size=100');
        self::assertSame([], $this->slugs());
        self::assertSame(0, $this->metaTotal(), 'a product of an unapproved category is not indexed');
        $client->request('GET', '/api/v1/storefront/products/'.$held);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/api/v1/storefront/products?category='.$approvedCategory.'&size=100');
        self::assertSame([$navigable], $this->slugs(), 'the approved category still serves its own product');

        $client->request('GET', '/api/v1/storefront/reference');
        self::assertResponseIsSuccessful();
        /** @var list<string> $taxonomy */
        $taxonomy = array_column($this->json()['categories'], 'slug');
        self::assertContains($approvedCategory, $taxonomy);
        self::assertNotContains($pendingCategory, $taxonomy, 'an unapproved category is never navigable');
    }

    /**
     * R27/R30 — the review routes resolve a product by slug like every other public read, so a row
     * the storefront may not show exposes no reviews and accepts none (`analyst-c.md`: any public
     * endpoint that resolves a product by slug applies the same predicate).
     */
    public function testTheReviewsOfANonPublicProductAreNeitherServedNorAccepted(): void
    {
        $category = $this->seedCategory();
        $published = $this->seedProduct($category);
        $pending = $this->seedProduct($category, 'PENDING_APPROVAL');
        $unpublished = $this->seedProduct($category, 'APPROVED', false);
        $this->seedReview($published);
        $this->seedReview($pending);
        $this->seedReview($unpublished);

        // No index rebuild: both review routes read PostgreSQL, so the search index cannot change
        // their answer and rebuilding it here would only cost a full Meilisearch round trip.
        $client = $this->client();

        $client->request('GET', '/api/v1/storefront/products/'.$published.'/reviews');
        self::assertResponseIsSuccessful('the public product keeps its reviews');
        self::assertNotSame([], $this->json()['items'], 'the public product really has a review to leak');

        $client->request('GET', '/api/v1/storefront/products/'.$pending.'/reviews');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a pending product has no readable reviews');

        $client->request('GET', '/api/v1/storefront/products/'.$unpublished.'/reviews');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'an unpublished product has no readable reviews');

        $client->request(
            'POST',
            '/api/v1/storefront/products/'.$pending.'/reviews',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['author' => 'Anonyme', 'rating' => 5, 'comment' => 'Tres bien'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a review cannot be attached to a hidden product');

        $client->request(
            'POST',
            '/api/v1/storefront/products/'.$published.'/reviews',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['author' => 'Anonyme', 'rating' => 5, 'comment' => 'Tres bien'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'the public product still accepts reviews');
    }

    /** A published-visible category, unless the case needs another state. */
    private function seedCategory(string $approvalStatus = 'APPROVED', bool $hidden = false): string
    {
        $slug = sprintf('vis-cat-%d', ++self::$sequence);

        $this->db()->insert('storefront_category', [
            'slug' => $slug,
            'label' => 'Visibilité '.$slug,
            'subcategories' => '[]',
            'quote_eligible' => false,
            'sort_order' => 950,
            'parent_slug' => null,
            'hidden' => $hidden,
            'approval_status' => $approvalStatus,
        ], [
            'quote_eligible' => ParameterType::BOOLEAN,
            'hidden' => ParameterType::BOOLEAN,
            'sort_order' => ParameterType::INTEGER,
        ]);

        $this->categorySlugs[] = $slug;

        return $slug;
    }

    /**
     * A product whose name, reference and description carry one unique token, so a search for that
     * token can only match this row.
     */
    private function seedProduct(string $category, string $approvalStatus = 'APPROVED', bool $published = true): string
    {
        $slug = sprintf('vis-%d', ++self::$sequence);
        $token = 'token'.bin2hex(random_bytes(6));

        $this->db()->insert('storefront_product_view', [
            'id' => Uuid::v7()->toRfc4122(),
            'slug' => $slug,
            'name' => 'Visibilité '.$token,
            'brand' => 'Zidane',
            'ref' => strtoupper($token),
            'category_slug' => $category,
            'subcategory' => 'Baignoire',
            'price_minor' => 99_900,
            'old_price_minor' => 129_900,
            'currency' => 'TND',
            'stock' => 'in',
            'material' => 'Acier',
            'dims' => '170x70',
            'image_path' => '/uploads/products/visibility.png',
            'rating' => 4.0,
            'reviews' => 1,
            'is_new' => false,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Produit de visibilité '.$token,
            'variants' => '[]',
            'approval_status' => $approvalStatus,
            'published' => $published,
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

        $this->productSlugs[] = $slug;
        $this->tokens[$slug] = $token;

        return $slug;
    }

    private function tokenOf(string $slug): string
    {
        $token = $this->tokens[$slug] ?? null;
        self::assertIsString($token, sprintf('no token was recorded for "%s"', $slug));

        return $token;
    }

    /** One review attached to the slug, so a leaking endpoint has something to leak. */
    private function seedReview(string $productSlug): void
    {
        $this->db()->insert('storefront_product_review', [
            'id' => Uuid::v7()->toRfc4122(),
            'product_slug' => $productSlug,
            'author_name' => 'Client '.$productSlug,
            'rating' => 5,
            'comment' => 'Avis sur '.$productSlug,
        ], [
            'rating' => ParameterType::INTEGER,
        ]);
    }

    /** `meta.total` of the response of the last request. */
    private function metaTotal(): int
    {
        /** @var array{total: int} $meta */
        $meta = $this->json()['meta'];

        return $meta['total'];
    }

    /** Puts the seeded rows into the projection through the real indexer, as a reindex would. */
    private function rebuild(): void
    {
        $this->indexer()->rebuildAll();
    }

    private function indexer(): StorefrontProductSearchIndexer
    {
        $indexer = static::getContainer()->get(StorefrontProductSearchIndexer::class);
        assert($indexer instanceof StorefrontProductSearchIndexer);

        return $indexer;
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return array_column($this->json()['items'], 'slug');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $this->client()->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function client(): KernelBrowser
    {
        $client = $this->client;
        self::assertInstanceOf(KernelBrowser::class, $client, 'setUp creates the browser this helper reuses');

        return $client;
    }

    private function db(): Connection
    {
        if (null === $this->connection) {
            /** @var Connection $connection */
            $connection = static::getContainer()->get(Connection::class);
            $this->connection = $connection;
        }

        return $this->connection;
    }
}
