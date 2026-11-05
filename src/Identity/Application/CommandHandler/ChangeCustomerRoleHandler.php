<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\ChangeCustomerRole;
use App\Identity\Application\Port\CustomerAccountReader;
use App\Identity\Application\Port\CustomerRoleStore;
use App\Identity\Application\Port\IdentityAuditPort;
use App\Identity\Domain\Model\AdminCustomerNotFoundException;
use App\Identity\Domain\Model\AssignableCustomerRole;
use App\Identity\Domain\Model\LastSuperAdminCannotBeDemotedException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R10/R11 — the single command.bus handler for the admin role editor (CMD-004, CMD-005).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ChangeCustomerRoleHandler
{
    public function __construct(
        private CustomerAccountReader $accounts,
        private CustomerRoleStore $roles,
        private IdentityAuditPort $audit,
    ) {
    }

    public function __invoke(ChangeCustomerRole $command): void
    {
        $role = AssignableCustomerRole::fromString($command->role);

        $target = $this->accounts->find($command->customerId);
        if (null === $target) {
            throw AdminCustomerNotFoundException::forCustomer($command->customerId);
        }
        $previousRole = $target->role;

        if ($previousRole === $role->name()) {
            // R14 audits role changes; re-assigning the stored role is not one, so nothing is
            // written and no audit row is appended.
            return;
        }

        // R13: an "active" super admin is status != 'banned' — the same predicate
        // DeleteCustomerWithAnonymizationHandler uses, so both guards agree on the account set.
        // Known window (spec Q12, same as the delete guard): the count and the write are not one
        // atomic statement, so two simultaneous demotions of the last two super admins could both
        // pass. Admin surfaces accept lost updates per Q12; a locking read is the fix if a human
        // ever decides R13 must survive concurrent demotions.
        if (AssignableCustomerRole::ROLE_SUPER_ADMIN === $previousRole && !$role->isSuperAdmin()) {
            if ($this->accounts->activeSuperAdminCount() <= 1) {
                throw LastSuperAdminCannotBeDemotedException::create();
            }
        }

        $this->roles->changeRole($command->customerId, $role->name());
        $this->audit->record(
            'USER_ROLE_CHANGED',
            $command->customerId,
            $command->adminId,
            sprintf('Changed role from %s to %s', $previousRole, $role->name()),
        );
    }
}
