<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\RemoveBrandImage;
use App\Catalog\Application\Port\BrandImageStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * spec-run-19 R6 — remove the brand image: the policy, the row update and the audit entry are
 * the store's, in the transaction the command-bus middleware opened. Removing when no image is
 * set is a success like any other (idempotent).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RemoveBrandImageHandler
{
    public function __construct(
        private BrandImageStore $store,
    ) {
    }

    public function __invoke(RemoveBrandImage $command): void
    {
        $this->store->removeImage($command->name, $command->actorId, $command->actorIsSuperAdmin);
    }
}
