<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\ChangeCustomerRole;
use App\Identity\Domain\Model\AdminCustomerNotFoundException;
use App\Identity\Domain\Model\AssignableCustomerRole;
use App\Identity\Domain\Model\LastSuperAdminCannotBeDemotedException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * R10/R11 — the single command.bus handler for the admin role editor (CMD-004, CMD-005).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ChangeCustomerRoleHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(ChangeCustomerRole $command): void
    {
        $role = AssignableCustomerRole::fromString($command->role);

        $target = $this->connection->fetchAssociative(
            'SELECT id, role, status FROM customer_profile WHERE id = :id',
            ['id' => $command->customerId],
        );
        if (false === $target) {
            throw AdminCustomerNotFoundException::forCustomer($command->customerId);
        }
        $previousRole = (string) ($target['role'] ?? '');

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
            $activeSuperAdmins = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM customer_profile WHERE role = 'ROLE_SUPER_ADMIN' AND status != 'banned'",
            );
            if ($activeSuperAdmins <= 1) {
                throw LastSuperAdminCannotBeDemotedException::create();
            }
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        $this->connection->executeStatement(
            'UPDATE customer_profile SET role = :role, updated_at = :updated_at WHERE id = :id',
            [
                'role' => $role->name(),
                'updated_at' => $now,
                'id' => $command->customerId,
            ],
        );

        $this->connection->executeStatement(
            'INSERT INTO identity_audit_log (id, action, target_user_id, actor_admin_id, reason, created_at)
             VALUES (:id, :action, :target_user_id, :actor_admin_id, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'action' => 'USER_ROLE_CHANGED',
                'target_user_id' => $command->customerId,
                'actor_admin_id' => $command->adminId,
                'reason' => sprintf('Changed role from %s to %s', $previousRole, $role->name()),
                'created_at' => $now,
            ],
        );
    }
}
