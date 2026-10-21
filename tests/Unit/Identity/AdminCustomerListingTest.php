<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Query\ListAdminCustomers;
use App\Identity\Domain\Model\AdminAccessRequiredException;
use App\Identity\Infrastructure\ReadModel\AdminCustomerFinder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * WP3 / R15 — the dashboard user list: pagination bounds and requester authorization.
 */
final class AdminCustomerListingTest extends TestCase
{
    private const REQUESTER = '01926b00-0000-7000-8000-0000000000c3';

    public function testLimitIsCappedAtFiftyAndPageNeverFallsBelowOne(): void
    {
        $query = new ListAdminCustomers(requesterId: self::REQUESTER, requesterIsAdmin: true, page: 0, limit: 500);

        self::assertSame(1, $query->effectivePage());
        self::assertSame(50, $query->effectiveLimit());
        self::assertSame(0, $query->effectiveOffset());

        $thirdPage = new ListAdminCustomers(requesterId: self::REQUESTER, requesterIsAdmin: true, page: 3, limit: 25);
        self::assertSame(3, $thirdPage->effectivePage());
        self::assertSame(25, $thirdPage->effectiveLimit());
        self::assertSame(50, $thirdPage->effectiveOffset());

        $zeroLimit = new ListAdminCustomers(requesterId: self::REQUESTER, requesterIsAdmin: true, page: 1, limit: 0);
        self::assertSame(1, $zeroLimit->effectiveLimit());
    }

    public function testFinderRefusesANonAdminRequesterBeforeReadingAnyTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('fetchAllAssociative');

        $finder = new AdminCustomerFinder($connection);

        $this->expectException(AdminAccessRequiredException::class);
        $this->expectExceptionMessage('Only an administrator account may list user accounts.');

        $finder(new ListAdminCustomers(requesterId: self::REQUESTER, requesterIsAdmin: false, page: 1, limit: 20));
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
            new ListAdminCustomers(requesterId: self::REQUESTER, requesterIsAdmin: true, page: 2, limit: 10),
        );

        self::assertSame(7, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame(10, $page->limit);

        $row = $page->items[0]->toArray();
        self::assertSame(['id', 'name', 'email', 'phone', 'role', 'status', 'orderCount'], array_keys($row));
        self::assertSame(3, $row['orderCount'], 'order count reaches the dashboard as an int');

        [$rowsSql, $rowsParams] = $reads[0];
        self::assertStringNotContainsString('password_hash', $rowsSql, 'R15 — the list never selects a credential column');
        self::assertStringNotContainsString('google_id', $rowsSql);
        self::assertStringNotContainsString('ban_reason', $rowsSql, 'R15 — ban internals stay out of the list');
        self::assertStringNotContainsString('banned_by', $rowsSql);
        self::assertStringContainsString('ORDER BY', $rowsSql);
        self::assertStringContainsString('LIMIT', $rowsSql);
        self::assertStringContainsString('OFFSET', $rowsSql);
        self::assertStringContainsString(':requester', $rowsSql, 'QRY-004 — the requester identity is part of the SQL');
        self::assertSame(self::REQUESTER, $rowsParams['requester']);
        self::assertSame(10, $rowsParams['limit']);
        self::assertSame(10, $rowsParams['offset']);
    }
}
