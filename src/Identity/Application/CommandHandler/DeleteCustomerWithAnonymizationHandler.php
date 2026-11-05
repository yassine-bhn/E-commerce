<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\DeleteCustomerWithAnonymization;
use App\Identity\Application\Port\CustomerAccountReader;
use App\Identity\Application\Port\CustomerDeletionReader;
use App\Identity\Application\Port\CustomerDeletionWriter;
use App\Identity\Application\Port\IdentityAuditPort;
use App\Identity\Domain\Model\LastSuperAdminCannotBeRemovedException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteCustomerWithAnonymizationHandler
{
    public function __construct(
        private CustomerAccountReader $accounts,
        private CustomerDeletionReader $deletionReads,
        private CustomerDeletionWriter $deletions,
        private IdentityAuditPort $audit,
    ) {
    }

    public function __invoke(DeleteCustomerWithAnonymization $command): void
    {
        $target = $this->accounts->find($command->customerId);

        if (null !== $target && 'ROLE_SUPER_ADMIN' === $target->role) {
            if ($this->accounts->activeSuperAdminCount() <= 1) {
                throw LastSuperAdminCannotBeRemovedException::create();
            }
        }

        $ordersCount = $this->deletionReads->orderCount($command->customerId);

        if ($ordersCount > 0) {
            // RG3.4: Orders exist -> Anonymize profile to preserve financial history and audit trail
            $anonEmail = sprintf('anonymized-%s@system.local', substr(hash('sha256', $command->customerId), 0, 12));

            $this->deletions->anonymize($command->customerId, $anonEmail);
            $this->audit->record(
                'USER_ANONYMIZED_WITH_ORDERS',
                $command->customerId,
                $command->adminId,
                $command->reason ?? 'Account deleted by request; order records anonymized',
            );

            return;
        }

        // No orders -> Hard delete profile and addresses
        $this->deletions->hardDelete($command->customerId);
        $this->audit->record(
            'USER_HARD_DELETED_NO_ORDERS',
            $command->customerId,
            $command->adminId,
            $command->reason ?? 'Account deleted without orders',
        );
    }
}
