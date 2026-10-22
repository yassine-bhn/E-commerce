<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\ChangeCustomerRole;
use App\Identity\Application\Query\ListAdminCustomers;
use App\Identity\Domain\Model\AdminAccessRequiredException;
use App\Identity\Infrastructure\ReadModel\AdminCustomerFinder;
use App\Identity\Infrastructure\ReadModel\AdminCustomerView;
use App\Shared\Application\Pagination\Page;
use App\Shared\Application\Pagination\PageRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * WP3 / R15 — the user-list query through the real query.bus against PostgreSQL: DTOs only,
 * requester authorization enforced inside the SQL, and provably no writes on the read side.
 */
final class AdminCustomerListQueryTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdOrderIds = [];

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->createdOrderIds) {
            $connection->executeStatement(
                'DELETE FROM ordering_orders WHERE id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->createdUserIds) {
            $connection->executeStatement(
                'DELETE FROM identity_audit_log WHERE target_user_id IN (:ids)',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->createdUserIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $this->createdOrderIds = [];
        $this->createdUserIds = [];

        parent::tearDown();
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    private function queryBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.query');
        assert($bus instanceof MessageBusInterface);

        return $bus;
    }

    private function insertUser(
        string $role,
        string $status = 'active',
        ?string $name = null,
        ?string $phone = null,
        ?string $createdAt = null,
    ): string {
        $id = Uuid::v4()->toRfc4122();
        $row = [
            'id' => $id,
            'name' => $name ?? 'ListUser '.substr($id, 0, 8),
            'email' => sprintf('wp3-list-%s@example.com', substr($id, 0, 12)),
            'phone' => $phone ?? '+216 20 00 00 01',
            'password_hash' => password_hash('Integration!Secret42', PASSWORD_BCRYPT),
            'role' => $role,
            'status' => $status,
        ];
        if (null !== $createdAt) {
            $row['created_at'] = $createdAt;
        }

        self::connection()->insert('customer_profile', $row);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function insertOrderFor(string $customerId): void
    {
        $id = Uuid::v4()->toRfc4122();
        self::connection()->insert('ordering_orders', [
            'id' => $id,
            'customer_id' => $customerId,
            'status' => 'placed',
            'order_number' => 'ZG-WP3-INT-'.substr($id, 0, 6),
            'total_minor' => 12_900,
            'currency' => 'TND',
        ]);
        $this->createdOrderIds[] = $id;
    }

    private function dispatch(ListAdminCustomers $query): Page
    {
        $envelope = $this->queryBus()->dispatch($query);
        $handled = $envelope->last(HandledStamp::class);
        self::assertNotNull($handled, 'the query must be handled on query.bus');

        $result = $handled->getResult();
        self::assertInstanceOf(Page::class, $result, 'QRY-002 — one page DTO, never an entity');

        return $result;
    }

    public function testAdminRequesterReadsDashboardRowsWithOrderCountsAndWithoutSecrets(): void
    {
        $connection = self::connection();
        $admin = $this->insertUser('ROLE_SUPER_ADMIN');
        $buyer = $this->insertUser('ROLE_USER');
        $this->insertOrderFor($buyer);
        $this->insertOrderFor($buyer);

        $auditBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM identity_audit_log');

        $page = $this->dispatch(new ListAdminCustomers(
            requesterId: $admin,
            requesterIsAdmin: true,
            page: new PageRequest(1, 500, ListAdminCustomers::MAX_SIZE),
        ));

        self::assertSame(50, $page->size, 'Q11 — the cap is enforced whatever the caller asks for');
        self::assertGreaterThanOrEqual(2, $page->total);

        $byId = [];
        foreach ($page->items as $item) {
            self::assertInstanceOf(AdminCustomerView::class, $item);
            $byId[$item->id] = $item;
        }

        self::assertArrayHasKey($buyer, $byId);
        self::assertSame(2, $byId[$buyer]->orderCount, 'the order count arrives from one aggregate subquery');
        self::assertSame('ROLE_USER', $byId[$buyer]->role);
        self::assertSame('active', $byId[$buyer]->status);
        self::assertSame('+216 20 00 00 01', $byId[$buyer]->phone);
        self::assertSame(
            ['id', 'name', 'email', 'phone', 'role', 'status', 'orderCount'],
            array_keys($byId[$buyer]->toArray()),
        );

        self::assertStringNotContainsString(
            'password',
            json_encode($page->toArray(), JSON_THROW_ON_ERROR),
            'R15 — no credential field ever reaches the read model',
        );
        self::assertSame($auditBefore, (int) $connection->fetchOne('SELECT COUNT(*) FROM identity_audit_log'), 'QRY-001 — the read wrote nothing');
    }

    public function testARequesterThatIsNotAnAdminInTheDatabaseReadsNothingEvenWithAForgedFlag(): void
    {
        $connection = self::connection();
        $admin = $this->insertUser('ROLE_ADMIN');
        $intruder = $this->insertUser('ROLE_USER');

        $legitimate = $this->dispatch(new ListAdminCustomers($admin, true, new PageRequest(1, 50)));
        self::assertNotEmpty($legitimate->items, 'an admin row does unlock the list');

        $forged = $this->dispatch(new ListAdminCustomers($intruder, true, new PageRequest(1, 50)));
        self::assertSame([], $forged->items, 'SEC-002 — the SQL itself rejects a non-admin requester');
        self::assertSame(0, $forged->total);
    }

    public function testTheFinderRefusesARequesterWithoutAnAdminFlagBeforeReading(): void
    {
        $admin = $this->insertUser('ROLE_SUPER_ADMIN');

        try {
            $this->dispatch(new ListAdminCustomers($admin, false, new PageRequest(1, 50)));
            self::fail('the finder must refuse a requester without the admin flag');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(AdminAccessRequiredException::class, $e->getPrevious());
        }
    }

    public function testPaginationDoesNotRepeatOrDropRowsAcrossPages(): void
    {
        $admin = $this->insertUser('ROLE_SUPER_ADMIN');
        $first = $this->insertUser('ROLE_USER');
        $second = $this->insertUser('ROLE_USER');

        $seen = [];
        for ($page = 1; $page <= 40; ++$page) {
            $view = $this->dispatch(new ListAdminCustomers($admin, true, new PageRequest($page, 1)));
            if ([] === $view->items) {
                break;
            }
            $seen[] = $view->items[0]->id;
        }

        self::assertSame($seen, array_values(array_unique($seen)), 'the ORDER BY tie-break is deterministic');
        self::assertContains($first, $seen);
        self::assertContains($second, $seen);
    }

    public function testQRoleAndStatusFiltersCombineAndTheTotalReflectsThem(): void
    {
        $admin = $this->insertUser('ROLE_SUPER_ADMIN');
        $token = 'Amira'.substr(bin2hex(random_bytes(3)), 0, 6);
        $hit = $this->insertUser('ROLE_USER', 'active', $token.' Active');
        $this->insertUser('ROLE_USER', 'banned', $token.' Banned');
        $this->insertUser('ROLE_ADMIN', 'active', $token.' Admin');

        $filtered = $this->dispatch(new ListAdminCustomers(
            requesterId: $admin,
            requesterIsAdmin: true,
            page: new PageRequest(1, 50),
            q: $token,
            role: 'ROLE_USER',
            status: 'active',
        ));

        self::assertSame(1, $filtered->total, 'the total counts the filtered rows only');
        self::assertSame([$hit], array_map(static fn (AdminCustomerView $item): string => $item->id, $filtered->items));

        $byText = $this->dispatch(new ListAdminCustomers(
            requesterId: $admin,
            requesterIsAdmin: true,
            page: new PageRequest(1, 50),
            q: $token,
        ));
        self::assertSame(3, $byText->total, 'q alone matches name, email or phone');
    }

    public function testTheDocumentedSortsOrderTheFilteredList(): void
    {
        $admin = $this->insertUser('ROLE_SUPER_ADMIN');
        $token = 'Sort'.substr(bin2hex(random_bytes(3)), 0, 6);
        $oldest = $this->insertUser('ROLE_USER', 'active', $token.' A', null, '2026-01-01 09:00:00+00');
        $middle = $this->insertUser('ROLE_USER', 'active', $token.' B', null, '2026-02-01 09:00:00+00');
        $newest = $this->insertUser('ROLE_USER', 'active', $token.' C', null, '2026-03-01 09:00:00+00');

        $this->insertOrderFor($middle);
        $this->insertOrderFor($middle);
        $this->insertOrderFor($newest);

        $ids = static fn (Page $page): array => array_map(
            static fn (AdminCustomerView $item): string => $item->id,
            $page->items,
        );

        $byName = $this->dispatch(new ListAdminCustomers($admin, true, new PageRequest(1, 50), q: $token));
        self::assertSame([$oldest, $middle, $newest], $ids($byName), 'name is the default sort');

        $byCreation = $this->dispatch(new ListAdminCustomers(
            requesterId: $admin,
            requesterIsAdmin: true,
            page: new PageRequest(1, 50),
            q: $token,
            sort: 'createdAt',
        ));
        self::assertSame([$newest, $middle, $oldest], $ids($byCreation), 'createdAt sorts newest first');

        $byOrders = $this->dispatch(new ListAdminCustomers(
            requesterId: $admin,
            requesterIsAdmin: true,
            page: new PageRequest(1, 50),
            q: $token,
            sort: 'orderCount',
        ));
        self::assertSame([$middle, $newest, $oldest], $ids($byOrders), 'orderCount sorts by the aggregate desc');
    }

    public function testTheWriteAndReadSidesEachHaveExactlyOneEntryClass(): void
    {
        self::bootKernel();

        // CMD-004 — one handler for ChangeCustomerRole across the whole source tree.
        $handlers = $this->srcFilesContaining('ChangeCustomerRole $command');
        self::assertSame(
            [dirname(__DIR__, 3).'/src/Identity/Application/CommandHandler/ChangeCustomerRoleHandler.php'],
            $handlers,
            'CMD-004 — the command has exactly one handler',
        );

        // QRY-003 — the read side is constructed from DBAL only, never the ORM.
        $constructor = (new \ReflectionClass(AdminCustomerFinder::class))->getConstructor();
        $parameters = $constructor?->getParameters() ?? [];
        self::assertCount(1, $parameters);

        $type = $parameters[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(Connection::class, $type->getName(), 'QRY-003 — DBAL only, no EntityManager');

        $finderSource = (string) file_get_contents(dirname(__DIR__, 3).'/src/Identity/Infrastructure/ReadModel/AdminCustomerFinder.php');
        self::assertStringNotContainsString('ORM', $finderSource);
        self::assertStringNotContainsString('EntityManager', $finderSource);
    }

    /**
     * @return list<string>
     */
    private function srcFilesContaining(string $needle): array
    {
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $hits[] = $file->getPathname();
            }
        }

        return $hits;
    }
}
