<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Query\ListAdminCustomers;
use App\Identity\Domain\Model\AdminAccessRequiredException;
use App\Identity\Infrastructure\ReadModel\AdminCustomerFinder;
use App\Shared\Application\Pagination\PageRequest;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * WP3 / R15 — the dashboard user list: pagination bounds, filter/sort vocabulary and requester
 * authorization (QRY-004 / SEC-002).
 */
final class AdminCustomerListingTest extends TestCase
{
    private const string REQUESTER = '01926b00-0000-7000-8000-0000000000c3';

    public function testSizeIsCappedAtFiftyAndPageNeverFallsBelowOne(): void
    {
        $query = new ListAdminCustomers(
            requesterId: self::REQUESTER,
            requesterIsAdmin: true,
            page: new PageRequest(0, 500, ListAdminCustomers::MAX_SIZE),
        );

        self::assertSame(1, $query->page->effectivePage());
        self::assertSame(50, $query->page->effectiveSize());
        self::assertSame(0, $query->page->effectiveOffset());

        $thirdPage = new ListAdminCustomers(self::REQUESTER, true, new PageRequest(3, 25));
        self::assertSame(3, $thirdPage->page->effectivePage());
        self::assertSame(25, $thirdPage->page->effectiveSize());
        self::assertSame(50, $thirdPage->page->effectiveOffset());

        $zeroSize = new ListAdminCustomers(self::REQUESTER, true, new PageRequest(1, 0));
        self::assertSame(1, $zeroSize->page->effectiveSize());
    }

    public function testTheSortFallsBackToNameWhenUnknown(): void
    {
        self::assertSame('name', (new ListAdminCustomers(self::REQUESTER, true))->effectiveSort());
        self::assertSame('name', (new ListAdminCustomers(self::REQUESTER, true, sort: 'inconnu'))->effectiveSort());
        self::assertSame('createdAt', (new ListAdminCustomers(self::REQUESTER, true, sort: 'createdAt'))->effectiveSort());
        self::assertSame('orderCount', (new ListAdminCustomers(self::REQUESTER, true, sort: 'orderCount'))->effectiveSort());
    }

    public function testFinderRefusesANonAdminRequesterBeforeReadingAnyTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('fetchAllAssociative');

        $finder = new AdminCustomerFinder($connection);

        $this->expectException(AdminAccessRequiredException::class);
        $this->expectExceptionMessage('Only an administrator account may list user accounts.');

        $finder(new ListAdminCustomers(self::REQUESTER, false, new PageRequest(1, 20)));
    }

    public function testAdminRequesterGetsRowsAndTotalInTwoQueriesWithNoSecretColumns(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(7);

        /** @var list<array{0: string, 1: array<string, mixed>}> $reads */
        $reads = [];
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$reads): array {
                $reads[] = [$sql, $params];

                return [[
                    'id' => '01926b00-0000-7000-8000-0000000000d4',
                    'name' => 'Sarra Trabelsi',
                    'email' => 'sarra@example.com',
                    'phone' => '+216 20 11 22 33',
                    'role' => 'ROLE_SUPER_ADMIN',
                    'status' => 'active',
                    'order_count' => '3',
                ]];
            });

        $page = (new AdminCustomerFinder($connection))(
            new ListAdminCustomers(self::REQUESTER, true, new PageRequest(2, 10)),
        );

        self::assertSame(7, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame(10, $page->size);

        $row = $page->items[0]->toArray();
        self::assertSame(['id', 'name', 'email', 'phone', 'role', 'status', 'orderCount'], array_keys($row));
        self::assertSame(3, $row['orderCount'], 'order count reaches the dashboard as an int');

        [$rowsSql, $rowsParams] = $reads[0];
        self::assertStringNotContainsString('password_hash', $rowsSql, 'R15 — the list never selects a credential column');
        self::assertStringNotContainsString('google_id', $rowsSql);
        self::assertStringNotContainsString('ban_reason', $rowsSql, 'R15 — ban internals stay out of the list');
        self::assertStringNotContainsString('banned_by', $rowsSql);
        self::assertStringContainsString('ORDER BY profile.name ASC', $rowsSql, 'name is the default sort');
        self::assertStringContainsString('LIMIT', $rowsSql);
        self::assertStringContainsString('OFFSET', $rowsSql);
        self::assertStringContainsString(':requester', $rowsSql, 'QRY-004 — the requester identity is part of the SQL');
        self::assertSame(self::REQUESTER, $rowsParams['requester']);
        self::assertSame(10, $rowsParams['limit']);
        self::assertSame(10, $rowsParams['offset']);
    }

    public function testTheFiltersAndTheOrderCountSortAreAppliedToBothStatements(): void
    {
        $connection = $this->createMock(Connection::class);

        /** @var list<array{0: string, 1: array<string, mixed>}> $countReads */
        $countReads = [];
        /** @var list<array{0: string, 1: array<string, mixed>}> $rowsReads */
        $rowsReads = [];
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$countReads): int {
                $countReads[] = [$sql, $params];

                return 3;
            });

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$rowsReads): array {
                $rowsReads[] = [$sql, $params];

                return [];
            });

        $page = (new AdminCustomerFinder($connection))(
            new ListAdminCustomers(
                requesterId: self::REQUESTER,
                requesterIsAdmin: true,
                page: new PageRequest(2, 10),
                q: 'amira',
                role: 'ROLE_USER',
                status: 'active',
                sort: 'orderCount',
            ),
        );

        self::assertSame(3, $page->total);
        self::assertSame([], $page->items);

        [$countSql, $countParams] = $countReads[0];
        foreach (['ILIKE', 'profile.role = :role', 'profile.status = :status', ':requester'] as $fragment) {
            self::assertStringContainsString($fragment, $countSql, 'the filtered total uses the same predicates');
        }
        self::assertSame('%amira%', $countParams['q'], 'the term is wrapped and escaped for ILIKE');
        self::assertSame('ROLE_USER', $countParams['role']);
        self::assertSame('active', $countParams['status']);
        self::assertSame(self::REQUESTER, $countParams['requester']);

        [$rowsSql, $rowsParams] = $rowsReads[0];
        self::assertStringContainsString('ORDER BY order_count DESC', $rowsSql, 'orderCount sorts by the aggregate');
        self::assertStringContainsString(':requester', $rowsSql);
        self::assertSame($countParams['q'], $rowsParams['q'], 'one filter set feeds both statements');
        self::assertSame(10, $rowsParams['limit']);
        self::assertSame(10, $rowsParams['offset']);
    }
}
