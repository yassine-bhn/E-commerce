<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Command\BanCustomer;
use App\Identity\Application\Command\DeleteCustomerWithAnonymization;
use App\Identity\Application\CommandHandler\BanCustomerHandler;
use App\Identity\Application\CommandHandler\DeleteCustomerWithAnonymizationHandler;
use App\Identity\Domain\Model\CannotBanAdminException;
use App\Identity\Domain\Model\LastSuperAdminCannotBeRemovedException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class UserManagementRulesTest extends TestCase
{
    public function testBanCustomerRequiresNonEmptyReason(): void
    {
        $connection = $this->createStub(Connection::class);
        $handler = new BanCustomerHandler($connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A valid reason must be provided when banning a user.');

        $command = new BanCustomer(
            customerId: '01926b00-0000-7000-8000-000000000001',
            adminId: 'admin-01',
            reason: '   ',
        );
        $handler($command);
    }

    public function testBanCustomerThrowsWhenTargetIsAnAdmin(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['id' => '01926b00-0000-7000-8000-000000000002', 'role' => 'ROLE_ADMIN']);

        $handler = new BanCustomerHandler($connection);

        $this->expectException(CannotBanAdminException::class);
        $this->expectExceptionMessage('Admins and SuperAdmins cannot be banned by standard administrative action.');

        $command = new BanCustomer(
            customerId: '01926b00-0000-7000-8000-000000000002',
            adminId: 'admin-01',
            reason: 'Suspected fraudulent behavior',
        );
        $handler($command);
    }

    public function testBanCustomerUpdatesStatusAndWritesAuditLog(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['id' => '01926b00-0000-7000-8000-000000000003', 'role' => 'ROLE_USER']);

        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params): int {
                if (str_contains($sql, 'UPDATE customer_profile')) {
                    self::assertSame('banned', $params['status']);
                    self::assertSame('Payment chargeback abuse', $params['ban_reason']);
                    self::assertSame('admin-01', $params['banned_by']);
                }
                if (str_contains($sql, 'INSERT INTO identity_audit_log')) {
                    self::assertSame('USER_BANNED', $params['action']);
                    self::assertSame('admin-01', $params['actor_admin_id']);
                }

                return 1;
            });

        $handler = new BanCustomerHandler($connection);
        $command = new BanCustomer(
            customerId: '01926b00-0000-7000-8000-000000000003',
            adminId: 'admin-01',
            reason: 'Payment chargeback abuse',
        );
        $handler($command);
    }

    public function testDeleteCustomerAnonymizesProfileWhenOrdersExist(): void
    {
        $connection = $this->createMock(Connection::class);

        // Check if user is super admin
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['id' => '01926b00-0000-7000-8000-000000000004', 'role' => 'ROLE_USER']);

        // Check order count
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(2); // Has 2 orders!

        $connection->expects(self::exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params): int {
                if (str_contains($sql, 'UPDATE customer_profile')) {
                    self::assertSame('Utilisateur anonymisé', $params['name']);
                    self::assertStringContainsString('anonymized-', $params['email']);
                    self::assertSame('anonymized', $params['status']);
                }
                if (str_contains($sql, 'INSERT INTO identity_audit_log')) {
                    self::assertSame('USER_ANONYMIZED_WITH_ORDERS', $params['action']);
                }

                return 1;
            });

        $handler = new DeleteCustomerWithAnonymizationHandler($connection);
        $command = new DeleteCustomerWithAnonymization(
            customerId: '01926b00-0000-7000-8000-000000000004',
            adminId: 'admin-01',
        );
        $handler($command);
    }

    public function testCannotRemoveLastSuperAdmin(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['id' => 'super-01', 'role' => 'ROLE_SUPER_ADMIN']);

        // Only 1 super admin exists
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(1);

        $handler = new DeleteCustomerWithAnonymizationHandler($connection);

        $this->expectException(LastSuperAdminCannotBeRemovedException::class);
        $this->expectExceptionMessage('The platform must retain at least one active SuperAdmin account.');

        $command = new DeleteCustomerWithAnonymization(
            customerId: 'super-01',
            adminId: 'super-01',
        );
        $handler($command);
    }
}
