<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Command\ChangeCustomerRole;
use App\Identity\Application\CommandHandler\ChangeCustomerRoleHandler;
use App\Identity\Application\Dto\CustomerRecord;
use App\Identity\Domain\Model\AdminCustomerNotFoundException;
use App\Identity\Domain\Model\LastSuperAdminCannotBeDemotedException;
use App\Identity\Domain\Model\RoleNotAssignableException;
use App\Tests\Unit\Identity\Support\InMemoryCustomerAccounts;
use App\Tests\Unit\Identity\Support\InMemoryIdentityAudit;
use PHPUnit\Framework\TestCase;

/**
 * WP3 — R10/R11/R12/R13/R14 domain rules of the admin role editor, over the account ports.
 */
final class ChangeCustomerRoleRulesTest extends TestCase
{
    private const TARGET = '01926b00-0000-7000-8000-0000000000a1';
    private const ACTOR = '01926b00-0000-7000-8000-0000000000b2';

    public function testRoleOutsideTheAssignableSetIsRefusedAndChangesNothing(): void
    {
        $accounts = $this->accounts(role: 'ROLE_USER');
        $audit = new InMemoryIdentityAudit();
        $handler = new ChangeCustomerRoleHandler($accounts, $accounts, $audit);

        $this->expectException(RoleNotAssignableException::class);
        $this->expectExceptionMessage('Only ROLE_USER and ROLE_ADMIN and ROLE_SUPER_ADMIN can be assigned to a user.');

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_GUEST',
        ));
    }

    public function testAssigningAnEmptyRoleIsRefusedAndChangesNothing(): void
    {
        $accounts = new InMemoryCustomerAccounts();
        $audit = new InMemoryIdentityAudit();
        $handler = new ChangeCustomerRoleHandler($accounts, $accounts, $audit);

        $this->expectException(RoleNotAssignableException::class);

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: '',
        ));
    }

    public function testRoleChangeWritesTheNewRoleAndAppendsExactlyOneAuditRow(): void
    {
        $accounts = $this->accounts(role: 'ROLE_USER');
        $audit = new InMemoryIdentityAudit();
        $handler = new ChangeCustomerRoleHandler($accounts, $accounts, $audit);

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_SUPER_ADMIN',
        ));

        self::assertCount(1, $accounts->roleChanges, 'exactly one role write');
        self::assertSame(self::TARGET, $accounts->roleChanges[0]['customerId']);
        self::assertSame('ROLE_SUPER_ADMIN', $accounts->roleChanges[0]['role']);

        self::assertCount(1, $audit->rows, 'exactly one audit row');
        self::assertSame('USER_ROLE_CHANGED', $audit->rows[0]['action']);
        self::assertSame(self::TARGET, $audit->rows[0]['targetUserId']);
        self::assertSame(self::ACTOR, $audit->rows[0]['actorAdminId']);
        self::assertStringContainsString('ROLE_USER', (string) $audit->rows[0]['reason'], 'audit row carries the previous role');
        self::assertStringContainsString('ROLE_SUPER_ADMIN', (string) $audit->rows[0]['reason'], 'audit row carries the new role');
    }

    public function testUnknownTargetIsRefusedWithoutAnyWrite(): void
    {
        $accounts = new InMemoryCustomerAccounts();
        $audit = new InMemoryIdentityAudit();
        $handler = new ChangeCustomerRoleHandler($accounts, $accounts, $audit);

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
        $accounts = $this->accounts(role: 'ROLE_SUPER_ADMIN');
        $accounts->activeSuperAdmins = 1;
        $audit = new InMemoryIdentityAudit();
        $handler = new ChangeCustomerRoleHandler($accounts, $accounts, $audit);

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
        $accounts = $this->accounts(role: 'ROLE_SUPER_ADMIN');
        $accounts->activeSuperAdmins = 1;
        $audit = new InMemoryIdentityAudit();
        $handler = new ChangeCustomerRoleHandler($accounts, $accounts, $audit);

        $this->expectException(LastSuperAdminCannotBeDemotedException::class);

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_USER',
        ));
    }

    public function testDemotionIsAllowedWhenAnotherActiveSuperAdminRemains(): void
    {
        $accounts = $this->accounts(role: 'ROLE_SUPER_ADMIN');
        $accounts->activeSuperAdmins = 2;
        $audit = new InMemoryIdentityAudit();
        $handler = new ChangeCustomerRoleHandler($accounts, $accounts, $audit);

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_USER',
        ));

        self::assertSame('ROLE_USER', $accounts->roleChanges[0]['role']);
        self::assertStringContainsString('ROLE_SUPER_ADMIN to ROLE_USER', (string) $audit->rows[0]['reason']);
    }

    public function testAssigningTheRoleTheUserAlreadyHoldsWritesNothing(): void
    {
        $accounts = $this->accounts(role: 'ROLE_SUPER_ADMIN');
        $audit = new InMemoryIdentityAudit();
        $handler = new ChangeCustomerRoleHandler($accounts, $accounts, $audit);

        $handler(new ChangeCustomerRole(
            customerId: self::TARGET,
            adminId: self::ACTOR,
            role: 'ROLE_SUPER_ADMIN',
        ));

        self::assertSame([], $accounts->roleChanges, 're-assigning the stored role writes nothing');
        self::assertSame([], $audit->rows, 're-assigning the stored role audits nothing');
    }

    private function accounts(string $role): InMemoryCustomerAccounts
    {
        $accounts = new InMemoryCustomerAccounts();
        $accounts->record = new CustomerRecord(
            id: self::TARGET,
            email: 'target@example.com',
            role: $role,
            status: 'active',
            googleId: null,
        );

        return $accounts;
    }
}
