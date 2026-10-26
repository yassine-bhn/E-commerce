<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * WP3 — R9..R15: the admin user-management surface (role editor, user list, audit trail) plus the
 * regression guard on the existing delete-user endpoint.
 */
final class AdminCustomerManagementTest extends WebTestCase
{
    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdOrderIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdOrderIds || [] !== $this->createdUserIds) {
            $connection = $this->connection();

            if ([] !== $this->createdOrderIds) {
                $connection->executeStatement(
                    'DELETE FROM ordering_order_lines WHERE order_id IN (:ids)',
                    ['ids' => $this->createdOrderIds],
                    ['ids' => ArrayParameterType::STRING],
                );
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
        }

        parent::tearDown();
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    private function createUser(
        string $role = 'ROLE_USER',
        string $status = 'active',
        ?string $password = null,
        ?string $name = null,
        ?string $createdAt = null,
    ): CustomerUser {
        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('wp3.%s.%s@example.com', strtolower(str_replace('ROLE_', '', $role)), substr($id, 0, 8));
        $name ??= 'WP3 '.ucwords(strtolower(str_replace('_', ' ', str_replace('ROLE_', '', $role)))).' User';

        $row = [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'phone' => '+216 20 '.substr($id, 0, 4),
            'password_hash' => null !== $password ? password_hash($password, PASSWORD_BCRYPT) : null,
            'role' => $role,
            'status' => $status,
        ];
        if (null !== $createdAt) {
            $row['created_at'] = $createdAt;
        }

        $this->connection()->insert('customer_profile', $row);

        $this->createdUserIds[] = $id;

        return new CustomerUser($id, $email, null, $role, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function storedRole(string $userId): string
    {
        return (string) $this->connection()->fetchOne('SELECT role FROM customer_profile WHERE id = :id', ['id' => $userId]);
    }

    private function auditRowCount(string $userId, string $action): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM identity_audit_log WHERE target_user_id = :id AND action = :action',
            ['id' => $userId, 'action' => $action],
        );
    }

    public function testGuestIsRejectedOnEveryNewAdminUserRoute(): void
    {
        $client = static::createClient();
        $target = $this->createUser();

        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$target->getId().'/role', ['role' => 'ROLE_SUPER_ADMIN']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('GET', '/api/v1/admin/customers');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        self::assertSame('ROLE_USER', $this->storedRole($target->getId()), 'a rejected write changes nothing');
    }

    public function testPlainCustomerIsRejectedOnEveryNewAdminUserRoute(): void
    {
        $client = static::createClient();
        $customer = $this->createUser();
        $target = $this->createUser();

        $client->loginUser($customer);

        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$target->getId().'/role', ['role' => 'ROLE_SUPER_ADMIN']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request('GET', '/api/v1/admin/customers');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame('ROLE_USER', $this->storedRole($target->getId()));
    }

    public function testStandardAdminIsRejectedOnTheRoleRouteAndNothingIsWritten(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('ROLE_ADMIN');
        $target = $this->createUser('ROLE_USER');

        $client->loginUser($admin);

        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$target->getId().'/role', ['role' => 'ROLE_ADMIN']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame('ROLE_USER', $this->storedRole($target->getId()));
        self::assertSame(0, $this->auditRowCount($target->getId(), 'USER_ROLE_CHANGED'));
    }

    public function testSuperAdminPromotesAUserWhoThenPowersTheRoleEditorAgain(): void
    {
        $client = static::createClient();
        $superAdmin = $this->createUser('ROLE_SUPER_ADMIN');
        $client->loginUser($superAdmin);

        $password = 'Promot3d!Account42';
        $promoted = $this->createUser('ROLE_USER', 'active', $password);
        $colleague = $this->createUser('ROLE_USER');

        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$promoted->getId().'/role', ['role' => 'ROLE_SUPER_ADMIN']);
        self::assertResponseIsSuccessful();
        // R15 — the change is visible in the dashboard list
        $client->request('GET', '/api/v1/admin/customers?size=50');
        self::assertResponseIsSuccessful();
        $items = $this->decode($client->getResponse())['items'] ?? [];
        self::assertIsArray($items);
        $row = null;
        foreach ($items as $candidate) {
            if (($candidate['id'] ?? null) === $promoted->getId()) {
                $row = $candidate;
            }
        }
        self::assertIsArray($row, 'the promoted user is listed');
        self::assertSame('ROLE_SUPER_ADMIN', $row['role']);

        // R14 — the existing audit endpoint returns the role change
        $client->request('GET', '/api/v1/admin/customers/'.$promoted->getId().'/audit');
        self::assertResponseIsSuccessful();
        $trail = $this->decode($client->getResponse())['auditTrail'] ?? [];
        self::assertIsArray($trail);
        self::assertCount(1, $trail);
        self::assertSame('USER_ROLE_CHANGED', $trail[0]['action'] ?? null);
        self::assertSame($superAdmin->getId(), $trail[0]['actor_admin_id'] ?? null);
        self::assertNotEmpty($trail[0]['created_at'] ?? null);
        self::assertStringContainsString('ROLE_USER', (string) ($trail[0]['reason'] ?? ''));
        self::assertStringContainsString('ROLE_SUPER_ADMIN', (string) ($trail[0]['reason'] ?? ''));

        // R10 — the promoted account sees exactly the stored role plus the implicit ROLE_USER,
        // read back through the real login path (CustomerProfileUserProvider).
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $promoted->getEmail(),
            'password' => $password,
        ]);
        self::assertResponseIsSuccessful();
        $roles = $this->decode($client->getResponse())['roles'] ?? [];
        self::assertIsArray($roles);
        self::assertContains('ROLE_SUPER_ADMIN', $roles);
        self::assertContains('ROLE_USER', $roles);
        self::assertCount(2, $roles, 'CustomerUser::getRoles() adds and drops nothing');

        // and the granted role is immediately effective on the admin surface for the next request
        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$colleague->getId().'/role', ['role' => 'ROLE_SUPER_ADMIN']);
        self::assertResponseIsSuccessful();
        self::assertSame('ROLE_SUPER_ADMIN', $this->storedRole($colleague->getId()));
    }

    public function testRoleOutsideTheAssignedSetIsRefusedAndChangesNothing(): void
    {
        $client = static::createClient();
        $superAdmin = $this->createUser('ROLE_SUPER_ADMIN');
        $target = $this->createUser('ROLE_USER');
        $client->loginUser($superAdmin);

        foreach (['ROLE_MODERATOR', '', 'root', 'role_user'] as $rejected) {
            $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$target->getId().'/role', ['role' => $rejected]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame('ROLE_USER', $this->storedRole($target->getId()));
            self::assertSame(0, $this->auditRowCount($target->getId(), 'USER_ROLE_CHANGED'));
        }

        // R11 — the staff role is part of the assignable set, so it is the refusals above that
        // bound the surface, not this value.
        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$target->getId().'/role', ['role' => 'ROLE_ADMIN']);
        self::assertResponseIsSuccessful();
        self::assertSame('ROLE_ADMIN', $this->storedRole($target->getId()));

        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$target->getId().'/role', []);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('ROLE_ADMIN', $this->storedRole($target->getId()));
    }

    public function testDemotingTheLastActiveSuperAdminIsRefusedWithNoWriteAndNoAuditRow(): void
    {
        $client = static::createClient();
        $connection = $this->connection();

        // Isolate the guard: park every other active super admin, restoring exactly those rows.
        $parkedIds = array_map(
            static fn (mixed $id): string => (string) $id,
            $connection->fetchFirstColumn(
                "SELECT id FROM customer_profile WHERE role = 'ROLE_SUPER_ADMIN' AND status != 'banned'",
            ),
        );
        $connection->executeStatement(
            "UPDATE customer_profile SET status = 'banned' WHERE role = 'ROLE_SUPER_ADMIN' AND status != 'banned'",
        );

        try {
            $sole = $this->createUser('ROLE_SUPER_ADMIN');
            $client->loginUser($sole);

            $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$sole->getId().'/role', ['role' => 'ROLE_USER']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertStringContainsString('at least one active SuperAdmin', (string) ($this->decode($client->getResponse())['error'] ?? ''));

            self::assertSame('ROLE_SUPER_ADMIN', $this->storedRole($sole->getId()));
            self::assertSame(0, $this->auditRowCount($sole->getId(), 'USER_ROLE_CHANGED'));
        } finally {
            if ([] !== $parkedIds) {
                $connection->executeStatement(
                    "UPDATE customer_profile SET status = 'active' WHERE role = 'ROLE_SUPER_ADMIN' AND id IN (:ids)",
                    ['ids' => $parkedIds],
                    ['ids' => ArrayParameterType::STRING],
                );
            }
        }
    }

    public function testDemotionSucceedsWhileAnotherActiveSuperAdminRemains(): void
    {
        $client = static::createClient();
        $actor = $this->createUser('ROLE_SUPER_ADMIN');
        $other = $this->createUser('ROLE_SUPER_ADMIN');
        $client->loginUser($actor);

        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$other->getId().'/role', ['role' => 'ROLE_USER']);
        self::assertResponseIsSuccessful();
        self::assertSame('ROLE_USER', $this->storedRole($other->getId()));
        self::assertSame(1, $this->auditRowCount($other->getId(), 'USER_ROLE_CHANGED'));
    }

    public function testRoleEndpointRejectsMalformedAndUnknownIds(): void
    {
        $client = static::createClient();
        $superAdmin = $this->createUser('ROLE_SUPER_ADMIN');
        $client->loginUser($superAdmin);

        $client->jsonRequest('PATCH', '/api/v1/admin/customers/not-a-uuid/role', ['role' => 'ROLE_USER']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $unknown = Uuid::v4()->toRfc4122();
        $client->jsonRequest('PATCH', '/api/v1/admin/customers/'.$unknown.'/role', ['role' => 'ROLE_SUPER_ADMIN']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->auditRowCount($unknown, 'USER_ROLE_CHANGED'), 'no phantom audit row');
    }

    public function testUserListExposesDashboardFieldsAndPaginationOnly(): void
    {
        $client = static::createClient();
        $connection = $this->connection();
        $admin = $this->createUser('ROLE_ADMIN', 'active', 'Listing!Admin42');
        $target = $this->createUser('ROLE_USER', 'active', 'Listing!User42');

        $orderId = Uuid::v4()->toRfc4122();
        $connection->insert('ordering_orders', [
            'id' => $orderId,
            'customer_id' => $target->getId(),
            'status' => 'placed',
            'order_number' => 'ZG-WP3-0001',
        ]);
        $this->createdOrderIds[] = $orderId;

        $client->loginUser($admin);

        $client->request('GET', '/api/v1/admin/customers?size=500&page=-3');
        self::assertResponseIsSuccessful();
        $payload = $this->decode($client->getResponse());

        self::assertSame(50, $payload['meta']['size'], 'Q11 — size is capped at 50');
        self::assertSame(1, $payload['meta']['page'], 'page never falls below 1');
        self::assertIsInt($payload['meta']['total']);
        self::assertGreaterThanOrEqual(2, $payload['meta']['total']);
        self::assertArrayHasKey('totalPages', $payload['meta']);
        self::assertArrayHasKey('hasMore', $payload['meta']);
        self::assertArrayNotHasKey('limit', $payload, 'the old limit key is gone');

        $rows = [];
        foreach ((array) $payload['items'] as $item) {
            self::assertIsArray($item);
            $rows[(string) $item['id']] = $item;
        }

        self::assertArrayHasKey($target->getId(), $rows);
        self::assertSame(['id', 'name', 'email', 'phone', 'role', 'status', 'orderCount'], array_keys($rows[$target->getId()]));
        self::assertSame(1, $rows[$target->getId()]['orderCount'], 'R15 — the order count reaches the list');
        self::assertSame('ROLE_USER', $rows[$target->getId()]['role']);
        self::assertSame('active', $rows[$target->getId()]['status']);

        self::assertStringNotContainsString('password', (string) $client->getResponse()->getContent(), 'R15 — no credential ever leaves');
        self::assertStringNotContainsString('ban_reason', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('banned_by', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('google_id', (string) $client->getResponse()->getContent());
    }

    public function testUserListPaginatesWithoutRepeatingRows(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('ROLE_SUPER_ADMIN');
        $first = $this->createUser('ROLE_USER');
        $second = $this->createUser('ROLE_USER');
        $client->loginUser($admin);

        $seen = [];
        $pages = 1;
        // The table is shared with every other test: walk the whole result set instead of assuming
        // the two new rows land on the first three pages of a database that may already have rows.
        for ($page = 1; $page <= $pages && $page <= 200; ++$page) {
            $client->request('GET', sprintf('/api/v1/admin/customers?size=1&page=%d', $page));
            self::assertResponseIsSuccessful();
            $payload = $this->decode($client->getResponse());
            $items = (array) $payload['items'];
            self::assertCount(1, $items, 'a size of 1 returns exactly one row');
            $seen[] = (string) $items[0]['id'];
            $pages = (int) $payload['meta']['total'];
        }

        self::assertSame($seen, array_unique($seen), 'pages never overlap: order is deterministic');
        self::assertContains($first->getId(), $seen);
        self::assertContains($second->getId(), $seen);
    }

    public function testUserListFiltersAndSortsThroughTheOffsetEnvelope(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('ROLE_ADMIN');
        $token = 'Flux'.substr(bin2hex(random_bytes(4)), 0, 8);

        $oldest = $this->createUser('ROLE_USER', 'active', null, $token.' Alpha', '2026-01-01 09:00:00+00');
        $middle = $this->createUser('ROLE_USER', 'active', null, $token.' Beta', '2026-02-01 09:00:00+00');
        $newest = $this->createUser('ROLE_USER', 'active', null, $token.' Gamma', '2026-03-01 09:00:00+00');
        $this->createUser('ROLE_USER', 'banned', null, $token.' Banned', '2026-04-01 09:00:00+00');
        $this->createUser('ROLE_ADMIN', 'active', null, $token.' Admin', '2026-05-01 09:00:00+00');

        $client->loginUser($admin);

        $client->request('GET', '/api/v1/admin/customers?'.http_build_query([
            'q' => $token,
            'role' => 'ROLE_USER',
            'status' => 'active',
            'sort' => 'createdAt',
            'page' => 1,
            'size' => 2,
        ]));
        self::assertResponseIsSuccessful();
        $first = $this->decode($client->getResponse());

        self::assertSame(
            ['page' => 1, 'size' => 2, 'total' => 3, 'totalPages' => 2, 'hasMore' => true],
            $first['meta'],
            'meta.total reflects the filtered count only',
        );
        self::assertSame([$newest->getId(), $middle->getId()], array_column($first['items'], 'id'));

        $client->request('GET', '/api/v1/admin/customers?'.http_build_query([
            'q' => $token,
            'role' => 'ROLE_USER',
            'status' => 'active',
            'sort' => 'createdAt',
            'page' => 2,
            'size' => 2,
        ]));
        self::assertResponseIsSuccessful();
        $second = $this->decode($client->getResponse());

        self::assertSame(['page' => 2, 'size' => 2, 'total' => 3, 'totalPages' => 2, 'hasMore' => false], $second['meta']);
        self::assertSame([$oldest->getId()], array_column($second['items'], 'id'));
    }

    public function testExistingDeleteUserContractIsUnchanged(): void
    {
        $client = static::createClient();
        $connection = $this->connection();
        $superAdmin = $this->createUser('ROLE_SUPER_ADMIN');
        $client->loginUser($superAdmin);

        // branch 1 — no orders: hard delete + its audit action
        $orderless = $this->createUser('ROLE_USER');
        $client->request('DELETE', '/api/v1/admin/customers/'.$orderless->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(
            false,
            $connection->fetchAssociative('SELECT id FROM customer_profile WHERE id = :id', ['id' => $orderless->getId()]),
            'a user without orders is deleted outright',
        );
        self::assertSame(1, $this->auditRowCount($orderless->getId(), 'USER_HARD_DELETED_NO_ORDERS'));

        // branch 2 — orders exist: anonymize, keep the row
        $buyer = $this->createUser('ROLE_USER');
        $orderId = Uuid::v4()->toRfc4122();
        $connection->insert('ordering_orders', [
            'id' => $orderId,
            'customer_id' => $buyer->getId(),
            'status' => 'placed',
            'order_number' => 'ZG-WP3-0002',
        ]);
        $this->createdOrderIds[] = $orderId;

        $client->request('DELETE', '/api/v1/admin/customers/'.$buyer->getId());
        self::assertResponseIsSuccessful();
        $row = $connection->fetchAssociative('SELECT status, email FROM customer_profile WHERE id = :id', ['id' => $buyer->getId()]);
        self::assertIsArray($row);
        self::assertSame('anonymized', $row['status']);
        self::assertStringStartsWith('anonymized-', (string) $row['email']);
        self::assertSame(1, $this->auditRowCount($buyer->getId(), 'USER_ANONYMIZED_WITH_ORDERS'));
    }
}
