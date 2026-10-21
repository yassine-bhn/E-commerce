<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\ChangeCustomerRole;
use App\Identity\Domain\Model\AdminCustomerNotFoundException;
use App\Identity\Domain\Model\LastSuperAdminCannotBeDemotedException;
use App\Identity\Domain\Model\RoleNotAssignableException;
use App\Identity\Infrastructure\Security\CustomerProfileUserProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * WP3 / R10-R14 — the role command through the real command.bus against PostgreSQL: the handler is
 * the transaction boundary (CMD-005), exactly one audit row per change, and refusals write nothing.
 */
final class ChangeCustomerRoleCommandTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdUserIds) {
            $connection = self::connection();
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
            $this->createdUserIds = [];
        }

        parent::tearDown();
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    private function commandBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.command');
        assert($bus instanceof MessageBusInterface);

        return $bus;
    }

    private function insertUser(string $role, string $status = 'active'): string
    {
        $id = Uuid::v4()->toRfc4122();
        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Integration '.$role,
            'email' => sprintf('wp3-integration-%s@example.com', substr($id, 0, 12)),
            'phone' => '',
            'role' => $role,
            'status' => $status,
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function storedRole(string $id): string
    {
        return (string) self::connection()->fetchOne('SELECT role FROM customer_profile WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $id): array
    {
        return self::connection()->fetchAllAssociative(
            'SELECT action, actor_admin_id, reason, created_at FROM identity_audit_log WHERE target_user_id = :id AND action = :action',
            ['id' => $id, 'action' => 'USER_ROLE_CHANGED'],
        );
    }

    public function testCommandBusWritesTheRoleOnceAndAppendsExactlyOneAuditRow(): void
    {
        $actor = $this->insertUser('ROLE_SUPER_ADMIN');
        $target = $this->insertUser('ROLE_USER');

        $this->commandBus()->dispatch(new ChangeCustomerRole(
            customerId: $target,
            adminId: $actor,
            role: 'ROLE_SUPER_ADMIN',
        ));

        self::assertSame('ROLE_SUPER_ADMIN', $this->storedRole($target));

        $rows = $this->auditRows($target);
        self::assertCount(1, $rows, 'R14 — exactly one audit row per role change');
        self::assertSame($actor, $rows[0]['actor_admin_id']);
        self::assertStringContainsString('ROLE_USER', (string) $rows[0]['reason']);
        self::assertStringContainsString('ROLE_SUPER_ADMIN', (string) $rows[0]['reason']);
        self::assertNotEmpty($rows[0]['created_at']);
    }

    public function testTheUserProviderHandsTheNewRoleToTheNextAuthenticatedRequest(): void
    {
        $actor = $this->insertUser('ROLE_SUPER_ADMIN');
        $target = $this->insertUser('ROLE_USER');

        $this->commandBus()->dispatch(new ChangeCustomerRole(
            customerId: $target,
            adminId: $actor,
            role: 'ROLE_SUPER_ADMIN',
        ));

        self::bootKernel();
        /** @var CustomerProfileUserProvider $provider */
        $provider = static::getContainer()->get(CustomerProfileUserProvider::class);
        $email = (string) self::connection()->fetchOne('SELECT email FROM customer_profile WHERE id = :id', ['id' => $target]);

        $user = $provider->loadUserByIdentifier($email);

        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_USER'], $user->getRoles(), 'R10 — nothing added, nothing dropped');
    }

    public function testAnOutOfRangeRoleFailsThroughTheBusWithoutWritingAnything(): void
    {
        $actor = $this->insertUser('ROLE_SUPER_ADMIN');
        $target = $this->insertUser('ROLE_USER');

        try {
            $this->commandBus()->dispatch(new ChangeCustomerRole(
                customerId: $target,
                adminId: $actor,
                role: 'ROLE_ADMIN',
            ));
            self::fail('the command bus must reject a role outside the assignable set');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(RoleNotAssignableException::class, $e->getPrevious());
            self::assertSame('ROLE_USER', $this->storedRole($target), 'R11 — a refused value changes nothing');
            self::assertSame([], $this->auditRows($target), 'R14 — a refusal is not a role change');
        }
    }

    public function testDemotingTheLastActiveSuperAdminIsRefusedWithNoWriteAndNoAuditRow(): void
    {
        $connection = self::connection();
        $parked = array_map(
            static fn (mixed $id): string => (string) $id,
            $connection->fetchFirstColumn("SELECT id FROM customer_profile WHERE role = 'ROLE_SUPER_ADMIN' AND status != 'banned'"),
        );
        $connection->executeStatement("UPDATE customer_profile SET status = 'banned' WHERE role = 'ROLE_SUPER_ADMIN' AND status != 'banned'");

        try {
            $sole = $this->insertUser('ROLE_SUPER_ADMIN');

            try {
                $this->commandBus()->dispatch(new ChangeCustomerRole(
                    customerId: $sole,
                    adminId: $sole,
                    role: 'ROLE_USER',
                ));
                self::fail('the command bus must refuse demoting the last active super admin');
            } catch (HandlerFailedException $e) {
                self::assertInstanceOf(LastSuperAdminCannotBeDemotedException::class, $e->getPrevious());
                self::assertSame('ROLE_SUPER_ADMIN', $this->storedRole($sole), 'R13 — no write happens');
                self::assertSame([], $this->auditRows($sole), 'R13 — no audit row for a refusal');
            }
        } finally {
            if ([] !== $parked) {
                $connection->executeStatement(
                    "UPDATE customer_profile SET status = 'active' WHERE id IN (:ids)",
                    ['ids' => $parked],
                    ['ids' => ArrayParameterType::STRING],
                );
            }
        }
    }

    public function testAnUnknownTargetFailsThroughTheBusWithoutAPhantomAuditRow(): void
    {
        $actor = $this->insertUser('ROLE_SUPER_ADMIN');
        $unknown = Uuid::v4()->toRfc4122();

        try {
            $this->commandBus()->dispatch(new ChangeCustomerRole(
                customerId: $unknown,
                adminId: $actor,
                role: 'ROLE_SUPER_ADMIN',
            ));
            self::fail('the command bus must refuse an unknown target account');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(AdminCustomerNotFoundException::class, $e->getPrevious());
            self::assertSame([], $this->auditRows($unknown), 'no phantom audit row for a missing account');
        }
    }
}
