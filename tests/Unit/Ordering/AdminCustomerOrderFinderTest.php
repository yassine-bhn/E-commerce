<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Query\ListOrdersForAdminRequestedCustomer;
use App\Ordering\Infrastructure\ReadModel\AdminCustomerOrderFinder;
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
 * handler (QRY-004) and refuses a request it must not serve before touching any table.
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

    public function testACustomerWithoutOrdersIsReadThroughTheOrderTablesOnly(): void
    {
        $connection = new ConnectionSpy();
        $connection->fetchOneResult = 1;

        $orders = (new AdminCustomerOrderFinder($connection))(new ListOrdersForAdminRequestedCustomer(
            Uuid::v7()->toRfc4122(),
            true,
            Uuid::v4()->toRfc4122(),
        ));

        self::assertSame([], $orders);
        self::assertCount(2, $connection->statements);
        self::assertStringNotContainsString('ordering_order_lines', implode(' ', $connection->statements));
    }
}

/**
 * Inline fake (TDD-008): records every statement the read model issues so a refusal that reads
 * first is caught, without ever opening a connection.
 */
final class ConnectionSpy extends Connection
{
    /** @var list<string> */
    public array $statements = [];

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

        return $this->fetchOneResult;
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $this->statements[] = $query;

        return false;
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->statements[] = $query;

        return [];
    }
}
