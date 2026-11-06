<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SetSubcategoryImage;
use App\Catalog\Application\Port\SubcategoryImageStore;
use App\Catalog\Domain\Model\UploadedImageReference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Attach or replace a sub-category picture: the reference rule is the domain value object's,
 * the policy, the tree update and the audit entry are the store's, in the transaction the
 * command-bus middleware opened.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class SetSubcategoryImageHandler
{
    public function __construct(
        private SubcategoryImageStore $store,
    ) {
    }

    public function __invoke(SetSubcategoryImage $command): void
    {
        $reference = new UploadedImageReference($command->image, UploadedImageReference::CATEGORIES);

        $this->store->setSubcategoryImage(
            $command->slug,
            $command->labelPath,
            $reference->path,
            $command->actorId,
            $command->actorIsSuperAdmin,
        );
    }
}
