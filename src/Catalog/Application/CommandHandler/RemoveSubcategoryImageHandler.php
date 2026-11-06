<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\RemoveSubcategoryImage;
use App\Catalog\Application\Port\SubcategoryImageStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Clear a sub-category picture: the policy, the tree update and the audit entry are the
 * store's, in the transaction the command-bus middleware opened.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RemoveSubcategoryImageHandler
{
    public function __construct(
        private SubcategoryImageStore $store,
    ) {
    }

    public function __invoke(RemoveSubcategoryImage $command): void
    {
        $this->store->removeSubcategoryImage(
            $command->slug,
            $command->labelPath,
            $command->actorId,
            $command->actorIsSuperAdmin,
        );
    }
}
