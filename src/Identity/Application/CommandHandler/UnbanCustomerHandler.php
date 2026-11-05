<?php

declare(strict_types=1);

namespace App\Identity\Application\CommandHandler;

use App\Identity\Application\Command\UnbanCustomer;
use App\Identity\Application\Port\CustomerStatusStore;
use App\Identity\Application\Port\IdentityAuditPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UnbanCustomerHandler
{
    public function __construct(
        private CustomerStatusStore $statuses,
        private IdentityAuditPort $audit,
    ) {
    }

    public function __invoke(UnbanCustomer $command): void
    {
        $this->statuses->unban($command->customerId);
        $this->audit->record('USER_UNBANNED', $command->customerId, $command->adminId, $command->reason);
    }
}
