<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Command\ChangeCustomerRole;
use App\Identity\Application\CommandHandler\ChangeCustomerRoleHandler;
use App\Identity\Domain\Model\AdminCustomerNotFoundException;
use App\Identity\Domain\Model\LastSuperAdminCannotBeDemotedException;
use App\Identity\Domain\Model\RoleNotAssignableException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * WP3 — R10/R11/R12/R13/R14 domain rules of the admin role editor.
 */
final class ChangeCustomerRoleRulesTest extends TestCase
{
    private const TARGET = '01926b00-0000-7000-8000-0000000000a1';
    private const ACTOR = '01926b00-0000-7000-8000-0000000000b2';

    public function testRoleOutsideTheAssignableSetIsRefusedAndChangesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturn(['id' => self::TARGET, 'role' => 'ROLE_USER', 'status' => 'active']);
        $connection->expects(self::never())->method('executeStatement');

        $handler = new ChangeCustomerRoleHandler($connection);

        $this->expectException(RoleNotAssignableException::class);
        $this->expectExceptionMessage('Only ROLE_USER and ROLE_SUPER_ADMIN can be assigned to a user.');

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_ADMIN',
        ));
    }

    public function testAssigningAnEmptyRoleIsRefusedAndChangesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $handler = new ChangeCustomerRoleHandler($connection);

        $this->expectException(RoleNotAssignableException::class);

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: '',
        ));
    }

    public function testRoleChangeWritesTheNewRoleAndAppendsExactlyOneAuditRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturn(['id' => self::TARGET, 'role' => 'ROLE_USER', 'status' => 'active']);

        /** @var list<array{0: string, 1: array<string, mixed>}> $statements */
        $statements = [];
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            });

        $handler = new ChangeCustomerRoleHandler($connection);
        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_SUPER_ADMIN',
        ));

        self::assertCount(2, $statements, 'exactly one role write plus one audit row');

        [$updateSql, $updateParams] = $statements[0];
        self::assertStringContainsString('UPDATE customer_profile', $updateSql);
        self::assertSame('ROLE_SUPER_ADMIN', $updateParams['role']);
        self::assertSame(self::TARGET, $updateParams['id']);

        [$insertSql, $auditParams] = $statements[1];
        self::assertStringContainsString('INSERT INTO identity_audit_log', $insertSql);
        self::assertSame('USER_ROLE_CHANGED', $auditParams['action']);
        self::assertSame(self::TARGET, $auditParams['target_user_id']);
        self::assertSame(self::ACTOR, $auditParams['actor_admin_id']);
        self::assertStringContainsString('ROLE_USER', (string) $auditParams['reason'], 'audit row carries the previous role');
        self::assertStringContainsString('ROLE_SUPER_ADMIN', (string) $auditParams['reason'], 'audit row carries the new role');
        self::assertNotEmpty((string) $auditParams['created_at'], 'audit row carries a timestamp');
    }

    public function testUnknownTargetIsRefusedWithoutAnyWrite(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::never())->method('executeStatement');

        $handler = new ChangeCustomerRoleHandler($connection);

        $this->expectException(AdminCustomerNotFoundException::class);
        $this->expectExceptionMessage('The selected user account does not exist.');

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_SUPER_ADMIN',
        ));
    }

    public function testSelfDemotionOfTheLastActiveSuperAdminIsRefusedWithoutAnyWrite(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturn(['id' => self::TARGET, 'role' => 'ROLE_SUPER_ADMIN', 'status' => 'active']);
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::never())->method('executeStatement');

        $handler = new ChangeCustomerRoleHandler($connection);

        $this->expectException(LastSuperAdminCannotBeDemotedException::class);
        $this->expectExceptionMessage('The platform must retain at least one active SuperAdmin account.');

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::TARGET,
            role: 'ROLE_USER',
        ));
    }

    public function testDemotionOfAnotherLastActiveSuperAdminIsRefusedWithoutAnyWrite(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturn(['id' => self::TARGET, 'role' => 'ROLE_SUPER_ADMIN', 'status' => 'active']);
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::never())->method('executeStatement');

        $handler = new ChangeCustomerRoleHandler($connection);

        $this->expectException(LastSuperAdminCannotBeDemotedException::class);

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_USER',
        ));
    }

    public function testDemotionIsAllowedWhenAnotherActiveSuperAdminRemains(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturn(['id' => self::TARGET, 'role' => 'ROLE_SUPER_ADMIN', 'status' => 'active']);
        $connection->method('fetchOne')->willReturn(2);

        /** @var list<array{0: string, 1: array<string, mixed>}> $statements */
        $statements = [];
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            });

        $handler = new ChangeCustomerRoleHandler($connection);
        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_USER',
        ));

        self::assertSame('ROLE_USER', $statements[0][1]['role']);
        self::assertStringContainsString('ROLE_SUPER_ADMIN to ROLE_USER', (string) $statements[1][1]['reason']);
    }

    public function testAssigningTheRoleTheUserAlreadyHoldsWritesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')
            ->willReturn(['id' => self::TARGET, 'role' => 'ROLE_SUPER_ADMIN', 'status' => 'active']);
        $connection->expects(self::never())->method('executeStatement');

        $handler = new ChangeCustomerRoleHandler($connection);
        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_SUPER_ADMIN',
        ));
    }
}
