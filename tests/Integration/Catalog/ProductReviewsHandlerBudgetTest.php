<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Query\ListProductReviews;
use App\Catalog\Infrastructure\ReadModel\ListProductReviewsHandler;
use App\Shared\Application\Pagination\CursorRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * R6 / PRF-001 — a reviews page costs exactly two statements whatever the page, the cursor or
 * the rating filter: one aggregate over the whole product and one keyset query.
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

        parent::tearDown();
    }

    public function testTheSecondPageCostsTheSameTwoStatementsAsTheFirst(): void
    {
        $handler = new ListProductReviewsHandler($this->connection);

        $this->connection->reset();
        $first = $handler(new ListProductReviews($this->slug, new CursorRequest(2, null, 50)));
        self::assertSame(['fetchAssociative' => 1, 'fetchAllAssociative' => 1], $this->connection->calls);
        self::assertTrue($first->hasMore);

        $this->connection->reset();
        $second = $handler(new ListProductReviews($this->slug, new CursorRequest(2, $first->nextCursor, 50)));
        self::assertSame(['fetchAssociative' => 1, 'fetchAllAssociative' => 1], $this->connection->calls);
        self::assertFalse($second->hasMore);
        self::assertCount(1, $second->items);
    }

    public function testTheRatingFilterKeepsTheStatementBudgetConstant(): void
    {
        $handler = new ListProductReviewsHandler($this->connection);

        $this->connection->reset();
        $page = $handler(new ListProductReviews($this->slug, new CursorRequest(10, null, 50), 4));

        self::assertSame(['fetchAssociative' => 1, 'fetchAllAssociative' => 1], $this->connection->calls);
        self::assertCount(1, $page->items);
        self::assertSame(3, $page->count, 'the aggregate ignores the rating filter');
        self::assertSame(4.0, $page->averageRating, 'the average ignores the rating filter');
    }
}

/**
 * Delegating wrapper that records which DBAL fetch methods the handler uses.
 */
final class CountingConnection extends Connection
{
    /** @var array<string, int> */
    public array $calls = [];

    private Connection $inner;

    public static function wrap(Connection $inner): self
    {
        $spy = new self($inner->getParams(), $inner->getDriver());
        $spy->inner = $inner;

        return $spy;
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $this->calls['fetchAssociative'] = ($this->calls['fetchAssociative'] ?? 0) + 1;

        return $this->inner->fetchAssociative($query, $params, $types);
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->calls['fetchAllAssociative'] = ($this->calls['fetchAllAssociative'] ?? 0) + 1;

        return $this->inner->fetchAllAssociative($query, $params, $types);
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        return $this->inner->insert($table, $data, $types);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return $this->inner->executeStatement($sql, $params, $types);
    }
}
