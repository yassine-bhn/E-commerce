<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\BanCustomer;
use App\Identity\Domain\Model\CannotBanAdminException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class BanCustomerHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(BanCustomer $command): void
    {
        $reason = trim($command->reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('A valid reason must be provided when banning a user.');
        }

        $user = $this->connection->fetchAssociative(
            'SELECT id, role FROM customer_profile WHERE id = :id',
            ['id' => $command->customerId],
        );

        if (false !== $user && isset($user['role'])) {
            $role = (string) $user['role'];
            if (in_array($role, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true)) {
                throw CannotBanAdminException::forRole($role);
            }
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        $this->connection->executeStatement(
            'UPDATE customer_profile
             SET status = :status, ban_reason = :ban_reason, banned_by = :banned_by, banned_at = :banned_at
             WHERE id = :id',
            [
                'status' => 'banned',
                'ban_reason' => $reason,
                'banned_by' => $command->adminId,
                'banned_at' => $now,
                'id' => $command->customerId,
            ],
        );

        $this->connection->executeStatement(
            'INSERT INTO identity_audit_log (id, action, target_user_id, actor_admin_id, reason, created_at)
             VALUES (:id, :action, :target_user_id, :actor_admin_id, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'action' => 'USER_BANNED',
                'target_user_id' => $command->customerId,
                'actor_admin_id' => $command->adminId,
                'reason' => $reason,
                'created_at' => $now,
            ],
        );
    }
}
