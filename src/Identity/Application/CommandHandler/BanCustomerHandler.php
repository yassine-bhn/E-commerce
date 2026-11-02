<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\BanCustomer;
use App\Identity\Application\Port\CustomerAccountReader;
use App\Identity\Application\Port\CustomerStatusStore;
use App\Identity\Application\Port\IdentityAuditPort;
use App\Identity\Domain\Model\CannotBanAdminException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class BanCustomerHandler
{
    public function __construct(
        private CustomerAccountReader $accounts,
        private CustomerStatusStore $statuses,
        private IdentityAuditPort $audit,
    ) {
    }

    public function __invoke(BanCustomer $command): void
    {
        $reason = trim($command->reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('A valid reason must be provided when banning a user.');
        }

        $user = $this->accounts->find($command->customerId);

        if (null !== $user && in_array($user->role, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true)) {
            throw CannotBanAdminException::forRole($user->role);
        }

        $this->statuses->ban($command->customerId, $reason, $command->adminId);
        $this->audit->record('USER_BANNED', $command->customerId, $command->adminId, $reason);
    }
}
