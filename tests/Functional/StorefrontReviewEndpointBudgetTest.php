<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * spec-run-12 R19 (PRF-001, QRY-004) — the reviews endpoint costs what its handlers cost.
 *
 * The limitation this test closes is specific: the guard that used to live in the controller raised
 * `GET …/reviews` from 2 statements to 3 and nothing measured it. The count comes from the Doctrine
 * data collector of the actual request, so a guard that reappears at the edge, or a second lookup
 * inside a handler, turns this red.
 */
final class StorefrontReviewEndpointBudgetTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $slug;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->slug = 'it-review-budget-'.bin2hex(random_bytes(4));

        $connection = self::connection();
        $connection->insert(
            'storefront_product_view',
            [
                'id' => '0192c000-7000-7000-9000-'.substr(hash('sha256', $this->slug), 0, 12),
                'slug' => $this->slug,
                'name' => 'Produit du budget HTTP',
                'brand' => 'Sopal',
                'ref' => strtoupper(bin2hex(random_bytes(4))),
                'category_slug' => 'sanitaires',
                'subcategory' => 'Sanitaires',
                'price_minor' => 8_000,
                'currency' => 'TND',
                'stock' => 'in',
                'material' => 'Acier',
                'dims' => '12 x 12 cm',
                'image_path' => '/uploads/products/it-review-budget.jpg',
                'rating' => 0.0,
                'reviews' => 0,
                'is_new' => false,
                'is_best' => false,
                'description' => 'Produit créé par le test de budget HTTP des avis.',
                'approval_status' => 'APPROVED',
                'published' => true,
            ],
            [
                'price_minor' => ParameterType::INTEGER,
                'rating' => ParameterType::STRING,
                'reviews' => ParameterType::INTEGER,
                'is_new' => ParameterType::BOOLEAN,
                'is_best' => ParameterType::BOOLEAN,
                'published' => ParameterType::BOOLEAN,
            ],
        );

        foreach ([5, 4] as $index => $rating) {
            $id = sprintf('0192c000-7000-7000-8000-%012d', $index);
            $connection->insert(
                'storefront_product_review',
                [
                    'id' => $id,
                    'product_slug' => $this->slug,
                    'author_name' => 'Client budget '.$index,
                    'rating' => $rating,
                    'comment' => 'Avis budget '.$index,
                ],
                ['rating' => ParameterType::INTEGER],
            );
        }
    }

    protected function tearDown(): void
    {
        // By slug, not by id: the POST test writes a review whose id the controller invented.
        self::connection()->executeStatement(
            'DELETE FROM storefront_product_review WHERE product_slug = :slug',
            ['slug' => $this->slug],
        );
        self::connection()->executeStatement(
            'DELETE FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        );

        parent::tearDown();
    }

    public function testReadingTheReviewsOfAPublicProductCostsTwoStatements(): void
    {
        $this->armStatementBudget();

        $this->client->request('GET', '/api/v1/storefront/products/'.$this->slug.'/reviews');

        self::assertResponseIsSuccessful();

        $statements = $this->requestStatements();
        self::assertCount(2, $statements, 'two reads: the aggregate that also answers visibility, then the page');
        self::assertStringStartsWith(
            'SELECT COUNT(*) AS total',
            $statements[0],
            'visibility is folded into the aggregate read instead of costing a statement of its own',
        );
        self::assertStringStartsWith('SELECT id, author_name, rating, comment, created_at', $statements[1]);
    }

    public function testReadingTheReviewsOfAHiddenProductFindsNothingForTheSameTwoStatements(): void
    {
        self::connection()->executeStatement(
            "UPDATE storefront_product_view SET approval_status = 'PENDING_APPROVAL' WHERE slug = :slug",
            ['slug' => $this->slug],
        );

        $this->armStatementBudget();

        $this->client->request('GET', '/api/v1/storefront/products/'.$this->slug.'/reviews');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertCount(
            1,
            $this->requestStatements(),
            'the aggregate answers visibility and the handler stops there: no page read, no lookup at the edge',
        );
    }

    public function testWritingAReviewCostsTheVisibilityCheckAndTheInsert(): void
    {
        $this->armStatementBudget();

        $this->client->jsonRequest('POST', '/api/v1/storefront/products/'.$this->slug.'/reviews', [
            'author' => 'Client budget',
            'rating' => 5,
            'comment' => 'Avis déposé par le test de budget.',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $statements = $this->requestStatements();
        self::assertCount(2, $statements, 'the visibility check and the insert, both in the handler');
        self::assertStringStartsWith(
            'SELECT 1 FROM storefront_product_view',
            $statements[0],
            'QRY-004: the decision belongs to the handler, not to the edge',
        );
        self::assertStringStartsWith('INSERT INTO storefront_product_review', $statements[1]);
    }

    public function testAReviewOfAHiddenProductIsRefusedWithTheSameTwoStatements(): void
    {
        self::connection()->executeStatement(
            "UPDATE storefront_product_view SET approval_status = 'PENDING_APPROVAL' WHERE slug = :slug",
            ['slug' => $this->slug],
        );

        $this->armStatementBudget();

        $this->client->jsonRequest('POST', '/api/v1/storefront/products/'.$this->slug.'/reviews', [
            'author' => 'Client budget',
            'rating' => 5,
            'comment' => 'Avis refusé par le test de budget.',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $statements = $this->requestStatements();
        self::assertCount(1, $statements, 'the guard answers before the insert, so nothing is written');
        self::assertStringStartsWith('SELECT 1 FROM storefront_product_view', $statements[0]);
    }

    /**
     * Arms the statement budget: keep the profile past the request, and empty the debug holder so
     * that only what the request issues is counted — setUp()'s fixtures were already written.
     */
    private function armStatementBudget(): void
    {
        $this->client->enableProfiler();

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
    }

    /**
     * The data statements the request issued, in order.
     *
     * DBAL logs the transaction envelope (`START TRANSACTION`, `COMMIT`, savepoints) exactly like a
     * query, but it is the command bus' `doctrine_transaction` middleware, not the handler: PRF-001
     * budgets what the endpoint reads and writes.
     *
     * @return list<string>
     */
    private function requestStatements(): array
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'the request profiled nothing, so its cost cannot be asserted');

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $statements = [];
        foreach ($collector->getQueries()['default'] ?? [] as $query) {
            $sql = trim((string) ($query['sql'] ?? ''));
            $verb = strtoupper(trim($sql, '"'));
            foreach (['START TRANSACTION', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE SAVEPOINT'] as $envelope) {
                if (str_starts_with($verb, $envelope)) {
                    continue 2;
                }
            }

            $statements[] = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        }

        return $statements;
    }

    private static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }
}
