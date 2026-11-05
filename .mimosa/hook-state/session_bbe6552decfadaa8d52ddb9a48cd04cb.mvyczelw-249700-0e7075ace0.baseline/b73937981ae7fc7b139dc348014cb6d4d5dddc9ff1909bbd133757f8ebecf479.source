<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\DeleteCustomerWithAnonymization;
use App\Identity\Domain\Model\LastSuperAdminCannotBeRemovedException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteCustomerWithAnonymizationHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(DeleteCustomerWithAnonymization $command): void
    {
        $target = $this->connection->fetchAssociative(
            'SELECT id, role FROM customer_profile WHERE id = :id',
            ['id' => $command->customerId],
        );

        if (false !== $target && 'ROLE_SUPER_ADMIN' === ($target['role'] ?? '')) {
            $superAdminCount = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM customer_profile WHERE role = 'ROLE_SUPER_ADMIN' AND status != 'banned'"
            );
            if ($superAdminCount <= 1) {
                throw LastSuperAdminCannotBeRemovedException::create();
            }
        }

        // Check if customer has any historical orders
        $ordersCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_orders WHERE customer_id = :customer_id',
            ['customer_id' => $command->customerId],
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');

        if ($ordersCount > 0) {
            // RG3.4: Orders exist -> Anonymize profile to preserve financial history and audit trail
            $anonEmail = sprintf('anonymized-%s@system.local', substr(hash('sha256', $command->customerId), 0, 12));

            $this->connection->executeStatement(
                'UPDATE customer_profile
                 SET name = :name, email = :email, phone = :phone, company = NULL, mf = NULL, status = :status, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'name' => 'Utilisateur anonymisé',
                    'email' => $anonEmail,
                    'phone' => '',
                    'status' => 'anonymized',
                    'updated_at' => $now,
                    'id' => $command->customerId,
                ],
            );

            // Anonymize saved addresses
            $this->connection->executeStatement(
                'DELETE FROM customer_address WHERE customer_id = :customer_id',
                ['customer_id' => $command->customerId],
            );

            $this->connection->executeStatement(
                'INSERT INTO identity_audit_log (id, action, target_user_id, actor_admin_id, reason, created_at)
                 VALUES (:id, :action, :target_user_id, :actor_admin_id, :reason, :created_at)',
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'action' => 'USER_ANONYMIZED_WITH_ORDERS',
                    'target_user_id' => $command->customerId,
                    'actor_admin_id' => $command->adminId,
                    'reason' => $command->reason ?? 'Account deleted by request; order records anonymized',
                    'created_at' => $now,
                ],
            );
        } else {
            // No orders -> Hard delete profile and addresses
            $this->connection->executeStatement(
                'DELETE FROM customer_address WHERE customer_id = :customer_id',
                ['customer_id' => $command->customerId],
            );
            $this->connection->executeStatement(
                'DELETE FROM customer_profile WHERE id = :id',
                ['id' => $command->customerId],
            );

            $this->connection->executeStatement(
                'INSERT INTO identity_audit_log (id, action, target_user_id, actor_admin_id, reason, created_at)
                 VALUES (:id, :action, :target_user_id, :actor_admin_id, :reason, :created_at)',
                [
                    'id' => Uuid::v7()->toRfc4122(),
                    'action' => 'USER_HARD_DELETED_NO_ORDERS',
                    'target_user_id' => $command->customerId,
                    'actor_admin_id' => $command->adminId,
                    'reason' => $command->reason ?? 'Account deleted without orders',
                    'created_at' => $now,
                ],
            );
        }
    }
}
