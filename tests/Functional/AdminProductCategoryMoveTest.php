<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Meilisearch\Exceptions\CommunicationException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-12 R3/R4 — a category move asserted on its own, not as a step of the generic update test.
 *
 * R3 needs two things from one test: the row really changes category and the trail gains exactly one
 * `CATALOG_PRODUCT_UPDATED` entry for that move (asserted in the index-free test below), and the
 * storefront consequence is visible on both sides — the product leaves every public read while it sits
 * in a category nobody approved, and comes back once that category is approved. The move is made by
 * the reviewer, so it never reopens the product (`afterReviewerModification`): the category gate alone
 * explains every absence below.
 *
 * R4 is the refusal: a destination that does not exist is answered 422 by validation, before the
 * transaction, so neither the row nor the trail changes.
 */
final class AdminProductCategoryMoveTest extends WebTestCase
{
    /** Seeded, approved and visible: a product needs a real parent to be created in. */
    private const string HOME_CATEGORY = 'sanitaires';

    private ?Connection $connection = null;
    private ?KernelBrowser $client = null;

    /** @var list<string> */
    private array $productSlugs = [];
    /** @var list<string> */
    private array $categorySlugs = [];
    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // One Super Admin for the whole test: every step is that actor's move, approval or publication.
        $this->client->loginUser($this->createUser());
    }

    /**
     * The public reads are index-backed. Only the test that reads them asks: a Meilisearch outage
     * skips that test, never the PostgreSQL facts R3 also requires.
     */
    private function requireIndex(): void
    {
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
            $targets = array_merge($this->productSlugs, $this->categorySlugs);
            if ([] !== $targets) {
                $connection->executeStatement(
                    'DELETE FROM catalog_audit_log WHERE target_slug IN (:slugs)',
                    ['slugs' => $targets],
                    ['slugs' => ArrayParameterType::STRING],
                );
                // Every write emits an outbox event, and the test database is shared: left pending,
                // these rows push the next suite's relay past its batch cap and make an unrelated
                // test read a read model that was never projected.
                foreach ($targets as $slug) {
                    $connection->executeStatement(
                        'DELETE FROM outbox WHERE payload::text LIKE :slug',
                        ['slug' => '%'.$slug.'%'],
                    );
                }
            }
            if ([] !== $this->userIds) {
                $connection->executeStatement(
                    'DELETE FROM customer_profile WHERE id IN (:slugs)',
                    ['slugs' => $this->userIds],
                    ['slugs' => ArrayParameterType::STRING],
                );
            }
        }

        // A deleted row must not survive as a document the next test reads without rebuilding.
        foreach (array_merge($this->productSlugs, $this->categorySlugs) as $slug) {
            $this->indexer()->forget($slug);
        }

        $this->productSlugs = [];
        $this->categorySlugs = [];
        $this->userIds = [];
        $this->connection = null;
        $this->client = null;

        parent::tearDown();
    }

    public function testAMoveIsStoredAndAuditedOnceAndKeepsTheRecordApproved(): void
    {
        $token = 'mv'.bin2hex(random_bytes(6));
        $slug = $this->createProduct($token);
        $this->approve($slug);
        $this->publish($slug);

        $target = $this->createCategory();
        $this->move($slug, $target);

        self::assertSame($target, $this->storedCategory($slug), 'the move is persisted, not queued');
        self::assertSame(
            ['CATALOG_PRODUCT_UPDATED'],
            $this->actions($slug, 'CATALOG_PRODUCT_UPDATED'),
            'one move is exactly one modification entry on the trail',
        );
        self::assertSame(
            'APPROVED',
            $this->storedStatus($slug),
            'the move is the reviewer\'s own save, so it never pushes the record back into review',
        );

        $this->move($slug, self::HOME_CATEGORY);

        self::assertSame(self::HOME_CATEGORY, $this->storedCategory($slug), 'the way back is a move like any other');
        self::assertSame(
            ['CATALOG_PRODUCT_UPDATED', 'CATALOG_PRODUCT_UPDATED'],
            $this->actions($slug, 'CATALOG_PRODUCT_UPDATED'),
            'moving back is a second modification, and only the first move wrote the first one',
        );
        self::assertSame('APPROVED', $this->storedStatus($slug), 'the way back keeps it approved too');
    }

    public function testAMovedProductIsPrivateUntilItsCategoryIsApproved(): void
    {
        $this->requireIndex();

        $client = $this->client();
        $token = 'mv'.bin2hex(random_bytes(6));
        $slug = $this->createProduct($token);
        $this->approve($slug);
        $this->publish($slug);
        $this->rebuild();

        self::assertSame(
            Response::HTTP_OK,
            $this->detailStatus($slug),
            'the product is public before the move, so every absence below is the move',
        );
        self::assertContains($slug, $this->browse(self::HOME_CATEGORY), 'a visible category serves its product');
        // The positive control for the SQL fallback below: this product carries an old price, so the
        // promotions predicate that reaches the fallback can never be what empties that page.
        self::assertContains(
            $slug,
            $this->browse(self::HOME_CATEGORY, sqlFallback: true),
            'the SQL fallback serves a visible product, so its empty answer after the move is about the category gate',
        );
        self::assertSame([$slug], $this->searchFor($token), 'the product is indexed where the storefront searches');

        $target = $this->createCategory();
        $this->move($slug, $target);
        // The trail and the still-approved status belong to the index-free test above; what matters
        // here is that the storefront stops serving the product.
        $this->rebuild();

        // The move is the reviewer's own save, so approval and publication both survive it: only the
        // destination category's missing approval can explain the absences below.
        self::assertSame('APPROVED', $this->storedStatus($slug));
        self::assertSame(Response::HTTP_NOT_FOUND, $this->detailStatus($slug), 'an approved product of an unapproved category is private');
        self::assertSame([], $this->browse($target), 'an unapproved category serves none of its products');
        self::assertSame([], $this->browse($target, sqlFallback: true), 'the SQL fallback applies the same category gate');
        self::assertNull($this->json()['facets'], 'the SQL fallback served this page, so the assertion above is about its own predicate');
        self::assertSame([], $this->searchFor($token), 'an approved product of an unapproved category is not searchable');
        self::assertNotContains($slug, $this->browse(self::HOME_CATEGORY), 'the product left the category it used to be browsed under');

        $this->approveCategory($target);
        $this->rebuild();

        self::assertSame(Response::HTTP_OK, $this->detailStatus($slug), 'approving the destination opens it for the approved product');
        self::assertContains($slug, $this->browse($target), 'the storefront browse serves it in its new category');
        self::assertSame([$slug], $this->searchFor($token), 'the search index carries it again');

        $this->move($slug, self::HOME_CATEGORY);
        $this->rebuild();

        self::assertSame(Response::HTTP_OK, $this->detailStatus($slug), 'back in an approved category, the product stays public');
        self::assertContains($slug, $this->browse(self::HOME_CATEGORY), 'the storefront browse lists it again');
        self::assertSame([$slug], $this->searchFor($token), 'the search index still carries it');

        $client->request('GET', '/api/v1/admin/catalog/products/'.$slug);
        self::assertResponseIsSuccessful();
        $stored = $this->json();
        self::assertSame(self::HOME_CATEGORY, $stored['category'], 'the admin read agrees with the row the storefront serves');
        self::assertIsArray($stored['approval']);
        self::assertTrue($stored['approval']['published'], 'a move never republishes or unpublishes: publication stayed where the reviewer left it');
    }

    public function testAMoveToAnUnknownCategoryIsRefusedAndWritesNeitherTheRowNorTheTrail(): void
    {
        $token = 'mv'.bin2hex(random_bytes(6));
        $slug = $this->createProduct($token);
        $this->approve($slug);
        $trail = $this->actions($slug);

        $unknown = 'mv-unknown-'.substr(Uuid::v7()->toRfc4122(), 0, 8);
        $this->client()->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, $unknown, $token));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a destination nobody created is a validation error, not a move');
        self::assertStringContainsString('Unknown category', (string) $this->json()['error']);
        self::assertSame(self::HOME_CATEGORY, $this->storedCategory($slug), 'the refused move wrote nothing');
        self::assertSame('APPROVED', $this->storedStatus($slug), 'the record was not reopened either');
        self::assertSame([], $this->actions($slug, 'CATALOG_PRODUCT_UPDATED'), 'no audit entry describes a move that did not happen');
        self::assertSame($trail, $this->actions($slug), 'the trail is byte-for-byte what it was');
    }

    /** Creates the product a Super Admin submitted, and returns its slug. */
    private function createProduct(string $token): string
    {
        $slug = 'mv-'.substr(Uuid::v7()->toRfc4122(), 0, 8);
        $this->client()->jsonRequest('POST', '/api/v1/admin/catalog/products', $this->payload($slug, self::HOME_CATEGORY, $token));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->productSlugs[] = $slug;

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $slug, string $category, string $token): array
    {
        return [
            'slug' => $slug,
            'name' => 'Produit de déplacement '.$token,
            'brand' => 'Sopal',
            'ref' => strtoupper($token),
            'category' => $category,
            'sub' => '',
            'price' => 200,
            // A promotion, so the `promotions=true` query the SQL fallback is reached with cannot be
            // the reason a page is empty: only the category gate can empty it.
            'oldPrice' => 250,
            'stock' => 'in',
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image' => '/p-move.jpg',
            'isNew' => false,
            'best' => false,
            'headrest' => false,
            'description' => 'Produit déplacé par le test de catégorie '.$token,
            'variants' => [],
        ];
    }

    /** A category the workflow created and nobody approved: products in it stay private. */
    private function createCategory(): string
    {
        $slug = 'mv-cat-'.substr(Uuid::v7()->toRfc4122(), 0, 8);
        $this->client()->jsonRequest('POST', '/api/v1/admin/catalog/categories', [
            'slug' => $slug,
            'label' => 'Catégorie de destination '.$slug,
            'quoteEligible' => false,
            'subcategories' => [],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('PENDING_APPROVAL', (string) $this->db()->fetchOne(
            'SELECT approval_status FROM storefront_category WHERE slug = :slug',
            ['slug' => $slug],
        ), 'a new category awaits approval, which is what makes the destination hide its products');
        $this->categorySlugs[] = $slug;

        return $slug;
    }

    private function move(string $slug, string $category): void
    {
        $token = (string) $this->db()->fetchOne('SELECT ref FROM storefront_product_view WHERE slug = :slug', ['slug' => $slug]);
        $this->client()->jsonRequest('PUT', '/api/v1/admin/catalog/products/'.$slug, $this->payload($slug, $category, strtolower($token)));
        self::assertResponseIsSuccessful(sprintf('moving "%s" to "%s" is refused', $slug, $category));
    }

    private function approve(string $slug): void
    {
        $this->client()->jsonRequest('POST', '/api/v1/admin/catalog/submissions/products/'.$slug.'/approve');
        self::assertResponseIsSuccessful(sprintf('the Super Admin could not approve "%s"', $slug));
    }

    private function approveCategory(string $slug): void
    {
        $this->client()->jsonRequest('POST', '/api/v1/admin/catalog/submissions/categories/'.$slug.'/approve');
        self::assertResponseIsSuccessful(sprintf('the Super Admin could not approve the category "%s"', $slug));
    }

    private function publish(string $slug): void
    {
        $this->client()->jsonRequest('PATCH', '/api/v1/admin/catalog/products/'.$slug.'/publication', ['published' => true]);
        self::assertResponseIsSuccessful(sprintf('the Super Admin could not publish "%s"', $slug));
    }

    private function storedCategory(string $slug): string
    {
        return (string) $this->db()->fetchOne(
            'SELECT category_slug FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    private function storedStatus(string $slug): string
    {
        return (string) $this->db()->fetchOne(
            'SELECT approval_status FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    /**
     * The trail of one target, optionally narrowed to a single action, in insertion order (the id is a
     * UUIDv7).
     *
     * @return list<string>
     */
    private function actions(string $slug, ?string $action = null): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT action FROM catalog_audit_log WHERE target_slug = :slug ORDER BY id ASC',
            ['slug' => $slug],
        );

        $actions = array_map(static fn (array $row): string => (string) $row['action'], $rows);

        return null === $action
            ? $actions
            : array_values(array_filter($actions, static fn (string $candidate): bool => $candidate === $action));
    }

    /** @return list<string> */
    private function browse(string $category, bool $sqlFallback = false): array
    {
        $query = '/api/v1/storefront/products?category='.$category.'&size=100';
        $this->client()->request('GET', $sqlFallback ? $query.'&promotions=true' : $query);
        self::assertResponseIsSuccessful();

        return $this->slugs();
    }

    /** @return list<string> */
    private function searchFor(string $token): array
    {
        $this->client()->request('GET', '/api/v1/storefront/products/search?q='.$token.'&size=100');
        self::assertResponseIsSuccessful();

        return $this->slugs();
    }

    private function detailStatus(string $slug): int
    {
        $this->client()->request('GET', '/api/v1/storefront/products/'.$slug);

        return $this->client()->getResponse()->getStatusCode();
    }

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
        $payload = json_decode((string) $this->client()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function client(): KernelBrowser
    {
        $client = $this->client;
        self::assertInstanceOf(KernelBrowser::class, $client, 'setUp creates the browser this helper reuses');

        return $client;
    }

    private function createUser(): CustomerUser
    {
        $id = Uuid::v7()->toRfc4122();
        $email = sprintf('catalog.move.%s@example.com', $id);
        $this->db()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Move Test ROLE_SUPER_ADMIN',
            'email' => $email,
            'password_hash' => null,
            'role' => 'ROLE_SUPER_ADMIN',
            'status' => 'active',
        ]);
        $this->userIds[] = $id;

        return new CustomerUser($id, $email, null, 'ROLE_SUPER_ADMIN', 'active');
    }

    /** Cached: the connection outlives any kernel the helpers boot. */
    private function db(): Connection
    {
        if (null === $this->connection) {
            $connection = static::getContainer()->get(Connection::class);
            assert($connection instanceof Connection);
            $this->connection = $connection;
        }

        return $this->connection;
    }
}
