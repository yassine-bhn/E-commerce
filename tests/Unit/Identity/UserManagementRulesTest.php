<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Command\BanCustomer;
use App\Identity\Application\Command\DeleteCustomerWithAnonymization;
use App\Identity\Application\CommandHandler\BanCustomerHandler;
use App\Identity\Application\CommandHandler\DeleteCustomerWithAnonymizationHandler;
use App\Identity\Application\Dto\CustomerRecord;
use App\Identity\Domain\Model\CannotBanAdminException;
use App\Identity\Domain\Model\LastSuperAdminCannotBeRemovedException;
use App\Tests\Unit\Identity\Support\InMemoryCustomerAccounts;
use App\Tests\Unit\Identity\Support\InMemoryIdentityAudit;
use PHPUnit\Framework\TestCase;

final class UserManagementRulesTest extends TestCase
{
    public function testBanCustomerRequiresNonEmptyReason(): void
    {
        $accounts = new InMemoryCustomerAccounts();
        $audit = new InMemoryIdentityAudit();
        $handler = new BanCustomerHandler($accounts, $accounts, $audit);

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
        $accounts = new InMemoryCustomerAccounts();
        $accounts->record = new CustomerRecord(
            id: '01926b00-0000-7000-8000-000000000002',
            email: 'admin@example.com',
            role: 'ROLE_ADMIN',
            status: 'active',
            googleId: null,
        );
        $audit = new InMemoryIdentityAudit();
        $handler = new BanCustomerHandler($accounts, $accounts, $audit);

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
        $accounts = new InMemoryCustomerAccounts();
        $accounts->record = new CustomerRecord(
            id: '01926b00-0000-7000-8000-000000000003',
            email: 'customer@example.com',
            role: 'ROLE_USER',
            status: 'active',
            googleId: null,
        );
        $audit = new InMemoryIdentityAudit();
        $handler = new BanCustomerHandler($accounts, $accounts, $audit);

        $handler(new BanCustomer(
            customerId: '01926b00-0000-7000-8000-000000000003',
            adminId: 'admin-01',
            reason: 'Payment chargeback abuse',
        ));

        self::assertCount(1, $accounts->bans, 'exactly one ban write');
        self::assertSame('01926b00-0000-7000-8000-000000000003', $accounts->bans[0]['customerId']);
        self::assertSame('Payment chargeback abuse', $accounts->bans[0]['reason']);
        self::assertSame('admin-01', $accounts->bans[0]['adminId']);

        self::assertCount(1, $audit->rows, 'exactly one audit row');
        self::assertSame('USER_BANNED', $audit->rows[0]['action']);
        self::assertSame('admin-01', $audit->rows[0]['actorAdminId']);
    }

    public function testDeleteCustomerAnonymizesProfileWhenOrdersExist(): void
    {
        $accounts = new InMemoryCustomerAccounts();
        $accounts->record = new CustomerRecord(
            id: '01926b00-0000-7000-8000-000000000004',
            email: 'customer@example.com',
            role: 'ROLE_USER',
            status: 'active',
            googleId: null,
        );
        $accounts->orders = 2;
        $audit = new InMemoryIdentityAudit();
        $handler = new DeleteCustomerWithAnonymizationHandler($accounts, $accounts, $accounts, $audit);

        $handler(new DeleteCustomerWithAnonymization(
            customerId: '01926b00-0000-7000-8000-000000000004',
            adminId: 'admin-01',
        ));

        self::assertCount(1, $accounts->anonymizations, 'the profile is anonymized, not deleted');
        self::assertStringContainsString('anonymized-', $accounts->anonymizations[0]['anonEmail']);
        self::assertSame([], $accounts->hardDeletes, 'a customer with orders is never hard-deleted');
        self::assertCount(1, $audit->rows);
        self::assertSame('USER_ANONYMIZED_WITH_ORDERS', $audit->rows[0]['action']);
    }

    public function testCannotRemoveLastSuperAdmin(): void
    {
        $accounts = new InMemoryCustomerAccounts();
        $accounts->record = new CustomerRecord(
            id: 'super-01',
            email: 'super@example.com',
            role: 'ROLE_SUPER_ADMIN',
            status: 'active',
            googleId: null,
        );
        $accounts->activeSuperAdmins = 1;
        $audit = new InMemoryIdentityAudit();
        $handler = new DeleteCustomerWithAnonymizationHandler($accounts, $accounts, $accounts, $audit);

        $this->expectException(LastSuperAdminCannotBeRemovedException::class);
        $this->expectExceptionMessage('The platform must retain at least one active SuperAdmin account.');

        $handler(new DeleteCustomerWithAnonymization(
            customerId: 'super-01',
            adminId: 'super-01',
        ));
    }
}
