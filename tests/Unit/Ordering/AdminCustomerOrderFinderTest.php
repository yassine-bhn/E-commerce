<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Query\ListOrdersForAdminRequestedCustomer;
use App\Ordering\Domain\Model\OrderSource;
use App\Ordering\Domain\Model\OrderStatus;
use App\Ordering\Infrastructure\ReadModel\AdminCustomerOrderFinder;
use App\Shared\Application\Pagination\Cursor;
use App\Shared\Application\Pagination\CursorPage;
use App\Shared\Application\Pagination\CursorRequest;
use App\Shared\Application\Pagination\InvalidCursorException;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * WP4 / R16 — the admin requested-customer order read authorizes the requester inside the
 * handler (QRY-004), refuses a request it must not serve before touching any table, and keysets
 * on `(placed_at, id)` with the documented filters.
 */
final class AdminCustomerOrderFinderTest extends TestCase
{
    public function testANonAdminRequesterIsRefusedInsideTheHandlerWithoutAnyQuery(): void
    {
        $connection = new ConnectionSpy();

        $this->expectException(AccessDeniedException::class);

        (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
            Uuid::v7()->toRfc4122(),
            false,
            Uuid::v4()->toRfc4122(),
        ));
    }

    public function testAMalformedCustomerIdIsRefusedWithoutAnyQuery(): void
    {
        $connection = new ConnectionSpy();

        $this->expectException(NotFoundHttpException::class);

        (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
            Uuid::v7()->toRfc4122(),
            true,
            'not-a-uuid',
        ));
    }

    public function testAnUnknownCustomerIsNotFoundBeforeTheOrderTablesAreRead(): void
    {
        $connection = new ConnectionSpy();
        $connection->fetchOneResult = false;

        $this->expectException(NotFoundHttpException::class);

        (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
            Uuid::v7()->toRfc4122(),
            true,
            Uuid::v4()->toRfc4122(),
        ));
    }

    public function testACustomerWithoutOrdersReturnsAnEmptyCursorPageWithoutTheLinesStatement(): void
    {
        $connection = new ConnectionSpy();
        $connection->fetchOneResult = 1;

        $page = (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
            Uuid::v7()->toRfc4122(),
            true,
            Uuid::v4()->toRfc4122(),
        ));

        self::assertInstanceOf(CursorPage::class, $page);
        self::assertSame([], $page->items);
        self::assertSame(20, $page->limit);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
        self::assertCount(2, $connection->statements, 'The existence check and one bounded order query.');
        self::assertStringNotContainsString('ordering_order_lines', implode(' ', $connection->statements));
    }

    public function testThePageAsksForOneExtraRowAndCarriesEveryFilterAndTheKeysetPredicate(): void
    {
        $connection = new ConnectionSpy();
        $connection->fetchOneResult = 1;
        $customerId = Uuid::v4()->toRfc4122();
        $cursorId = Uuid::v4()->toRfc4122();

        $page = (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
            requesterId: Uuid::v7()->toRfc4122(),
            requesterIsAdmin: true,
            customerId: $customerId,
            cursor: new CursorRequest(2, Cursor::encode('2026-10-20 11:00:00+00', $cursorId), ListOrdersForAdminRequestedCustomer::MAX_LIMIT),
            status: 'expediee',
            dateFrom: new \DateTimeImmutable('2026-10-01'),
            dateTo: new \DateTimeImmutable('2026-10-05'),
            q: 'ZG-FUNC',
        ));

        self::assertSame(2, $page->limit);

        $sql = $connection->statements[1] ?? '';
        self::assertStringContainsString('FROM ordering_orders', $sql);
        self::assertStringContainsString('customer_id = :customer', $sql);
        self::assertStringContainsString('order_source = :source', $sql);
        self::assertStringContainsString('status IN (:status_0)', $sql);
        self::assertStringContainsString('placed_at >= CAST(:date_from AS date)', $sql);
        self::assertStringContainsString("placed_at < CAST(:date_to AS date) + INTERVAL '1 day'", $sql);
        self::assertStringContainsString("order_number ILIKE :q ESCAPE '\\'", $sql);
        self::assertStringContainsString('(placed_at, id) < (CAST(:cursor_key AS timestamptz), CAST(:cursor_id AS uuid))', $sql);
        self::assertStringContainsString('ORDER BY placed_at DESC, id DESC', $sql);
        self::assertStringContainsString('LIMIT 3', $sql, 'limit + 1 rows decide hasMore without a second query.');

        /** @var array<string, mixed> $params */
        $params = $connection->statementParams[1] ?? [];
        self::assertSame($customerId, $params['customer']);
        self::assertSame(OrderSource::ONLINE->value, $params['source']);
        self::assertSame(OrderStatus::SHIPPED->value, $params['status_0']);
        self::assertSame('2026-10-01', $params['date_from']);
        self::assertSame('2026-10-05', $params['date_to']);
        self::assertSame('%ZG-FUNC%', $params['q']);
        self::assertSame('2026-10-20 11:00:00+00', $params['cursor_key']);
        self::assertSame($cursorId, $params['cursor_id']);
    }

    public function testAnUnknownStatusSlugMatchesNothingInsteadOfLeakingRows(): void
    {
        $connection = new ConnectionSpy();
        $connection->fetchOneResult = 1;

        (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
            requesterId: Uuid::v7()->toRfc4122(),
            requesterIsAdmin: true,
            customerId: Uuid::v4()->toRfc4122(),
            status: 'not-a-status',
        ));

        self::assertStringContainsString('1 = 0', $connection->statements[1] ?? '');
    }

    public function testTheLimitIsClampedToTheMaximumBeforeTheExtraRowIsFetched(): void
    {
        $connection = new ConnectionSpy();
        $connection->fetchOneResult = 1;

        $page = (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
            requesterId: Uuid::v7()->toRfc4122(),
            requesterIsAdmin: true,
            customerId: Uuid::v4()->toRfc4122(),
            cursor: new CursorRequest(500, null, ListOrdersForAdminRequestedCustomer::MAX_LIMIT),
        ));

        self::assertSame(ListOrdersForAdminRequestedCustomer::MAX_LIMIT, $page->limit);
        self::assertStringContainsString('LIMIT 101', $connection->statements[1] ?? '');
    }

    public function testAMalformedCursorIsRejectedAfterTheExistenceCheckAndBeforeTheOrderQuery(): void
    {
        $connection = new ConnectionSpy();
        $connection->fetchOneResult = 1;

        try {
            (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
                requesterId: Uuid::v7()->toRfc4122(),
                requesterIsAdmin: true,
                customerId: Uuid::v4()->toRfc4122(),
                cursor: new CursorRequest(20, 'not-base64'),
            ));

            self::fail('A cursor that cannot be decoded must be rejected, never silently ignored.');
        } catch (InvalidCursorException) {
        }

        self::assertCount(1, $connection->statements, 'Only the existence check ran; no order query, no full scan.');
    }
}

/**
 * Inline fake (TDD-008): records every statement and its parameters so a refusal that reads
 * first, a dropped filter or a forgotten keyset predicate is caught, without ever opening a
 * connection.
 */
final class ConnectionSpy extends Connection
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<array<int|string, mixed>> */
    public array $statementParams = [];

    public mixed $fetchOneResult = 1;

    /**
     * Chains to the parent so the spy stays a real Doctrine\DBAL\Connection, but every read is
     * intercepted below, so no driver is ever asked to connect.
     *
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [], ?Driver $driver = null, ?Configuration $config = null)
    {
        parent::__construct($params, $driver ?? new PgSQLDriver(), $config);
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        $this->statements[] = $query;
        $this->statementParams[] = $params;

        return $this->fetchOneResult;
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $this->statements[] = $query;
        $this->statementParams[] = $params;

        return false;
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->statements[] = $query;
        $this->statementParams[] = $params;

        return [];
    }
}
