<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Query\ListProductReviews;
use App\Catalog\Infrastructure\ReadModel\ListProductReviewsHandler;
use App\Shared\Application\Pagination\CursorRequest;
use App\Tests\Support\CountingConnection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * R6 / PRF-001 — a reviews page costs exactly two statements whatever the page, the cursor or
 * the rating filter: one aggregate over the whole product and one keyset query.
 *
 * spec-run-12 R16/R19 — the aggregate also carries the storefront visibility answer, so the
 * qualification the endpoint used to make with a third statement is decided by the handler without
 * widening the budget: a product the storefront hides yields no page, and still costs two.
 */
final class ProductReviewsHandlerBudgetTest extends KernelTestCase
{
    private CountingConnection $connection;

    private string $slug;

    /** @var list<string> */
    private array $reviewIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);
        $this->connection = CountingConnection::wrap($connection);

        $this->slug = 'it-reviews-budget-'.bin2hex(random_bytes(4));

        // The reviews route answers for the products the storefront serves, so the fixture is a
        // public product of a visible category (spec-run-12 R16).
        $this->connection->insert(
            'storefront_product_view',
            [
                'id' => '0192b000-7000-7000-9000-'.substr(hash('sha256', $this->slug), 0, 12),
                'slug' => $this->slug,
                'name' => 'Produit du budget d\'avis',
                'brand' => 'Sopal',
                'ref' => strtoupper(bin2hex(random_bytes(4))),
                'category_slug' => 'sanitaires',
                'subcategory' => 'Sanitaires',
                'price_minor' => 9_000,
                'currency' => 'TND',
                'stock' => 'in',
                'material' => 'Acier',
                'dims' => '10 x 10 cm',
                'image_path' => '/uploads/products/it-reviews-budget.jpg',
                'rating' => 0.0,
                'reviews' => 0,
                'is_new' => false,
                'is_best' => false,
                'description' => 'Produit créé par le test de budget des avis.',
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

        $base = new \DateTimeImmutable('2026-02-01 09:00:00+00:00');
        foreach ([5, 4, 3] as $index => $rating) {
            $id = sprintf('0192b000-7000-7000-8000-%012d', $index);
            $this->connection->insert(
                'storefront_product_review',
                [
                    'id' => $id,
                    'product_slug' => $this->slug,
                    'author_name' => 'Budget Reviewer '.$index,
                    'rating' => $rating,
                    'comment' => 'Budget review '.$index,
                    'created_at' => $base->modify(sprintf('+%d seconds', $index))->format('Y-m-d H:i:sP'),
                ],
                ['rating' => ParameterType::INTEGER],
            );
            $this->reviewIds[] = $id;
        }
    }

    protected function tearDown(): void
    {
        if ([] !== $this->reviewIds) {
            $this->connection->executeStatement(
                'DELETE FROM storefront_product_review WHERE id IN (:ids)',
                ['ids' => $this->reviewIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        $this->reviewIds = [];

        $this->connection->executeStatement(
            'DELETE FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $this->slug],
        );

        parent::tearDown();
    }

    public function testTheSecondPageCostsTheSameTwoStatementsAsTheFirst(): void
    {
        $handler = new ListProductReviewsHandler($this->connection);

        $this->connection->reset();
        $first = $handler(new ListProductReviews($this->slug, new CursorRequest(2, null, 50)));
        self::assertSame(['fetchAssociative' => 1, 'fetchAllAssociative' => 1], $this->connection->calls);
        self::assertNotNull($first);
        self::assertTrue($first->hasMore);

        $this->connection->reset();
        $second = $handler(new ListProductReviews($this->slug, new CursorRequest(2, $first->nextCursor, 50)));
        self::assertSame(['fetchAssociative' => 1, 'fetchAllAssociative' => 1], $this->connection->calls);
        self::assertNotNull($second);
        self::assertFalse($second->hasMore);
        self::assertCount(1, $second->items);
    }

    public function testTheRatingFilterKeepsTheStatementBudgetConstant(): void
    {
        $handler = new ListProductReviewsHandler($this->connection);

        $this->connection->reset();
        // The adapter counts what the request asks the database, so the budget is the query count,
        // not a number restated in the test.
        $page = $handler(new ListProductReviews($this->slug, new CursorRequest(10, null, 50), 4));

        self::assertSame(['fetchAssociative' => 1, 'fetchAllAssociative' => 1], $this->connection->calls);
        self::assertNotNull($page);
        self::assertCount(1, $page->items);
        self::assertSame(3, $page->count, 'the aggregate ignores the rating filter');
        self::assertSame(4.0, $page->averageRating, 'the average ignores the rating filter');
    }

    public function testAProductTheStorefrontHidesYieldsNoPageForTheSameTwoStatements(): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_product_view SET published = false WHERE slug = :slug',
            ['slug' => $this->slug],
        );

        $handler = new ListProductReviewsHandler($this->connection);

        $this->connection->reset();
        self::assertNull(
            $handler(new ListProductReviews($this->slug, new CursorRequest(10, null, 50))),
            'a review of a hidden product is not an empty page, it does not exist',
        );

        self::assertSame(
            ['fetchAssociative' => 1],
            $this->connection->calls,
            'the visibility answer rides in the aggregate, so the refusal costs one statement and never reads a review row',
        );
    }
}
