<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SetBrandImage;
use App\Catalog\Application\Port\BrandImageStore;
use App\Catalog\Domain\Model\BrandImageReference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * spec-run-19 R3/R5 — attach or replace the brand image: the reference rule is the domain value
 * object's, the policy, the row update and the audit entry are the store's, in the transaction
 * the command-bus middleware opened.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class SetBrandImageHandler
{
    public function __construct(
        private BrandImageStore $store,
    ) {
    }

    public function __invoke(SetBrandImage $command): void
    {
        $reference = new BrandImageReference($command->image);

        $this->store->setImage($command->name, $reference->path, $command->actorId, $command->actorIsSuperAdmin);
    }
}
