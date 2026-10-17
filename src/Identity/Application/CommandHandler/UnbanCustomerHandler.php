<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\UnbanCustomer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class UnbanCustomerHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(UnbanCustomer $command): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        $this->connection->executeStatement(
            'UPDATE customer_profile
             SET status = :status, ban_reason = NULL, banned_by = NULL, banned_at = NULL
             WHERE id = :id',
            [
                'status' => 'active',
                'id' => $command->customerId,
            ],
        );

        $this->connection->executeStatement(
            'INSERT INTO identity_audit_log (id, action, target_user_id, actor_admin_id, reason, created_at)
             VALUES (:id, :action, :target_user_id, :actor_admin_id, :reason, :created_at)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'action' => 'USER_UNBANNED',
                'target_user_id' => $command->customerId,
                'actor_admin_id' => $command->adminId,
                'reason' => $command->reason,
                'created_at' => $now,
            ],
        );
    }
}
