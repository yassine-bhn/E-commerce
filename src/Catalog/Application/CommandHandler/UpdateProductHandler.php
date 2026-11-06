<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\UpdateProduct;
use App\Catalog\Application\Port\AdminProductWriter;
use App\Catalog\Application\Port\CatalogSubmissionOwnerEditor;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Shared\Application\Port\ProductSearchIndexPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.11 — the product update: the submission is authorized before anything is written,
 * the writer records the content and its audit, and the reviewer/owner transition follows the
 * caller's role (the reviewer validates by writing — his own save is never queued for himself, R7).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateProductHandler
{
    public function __construct(
        private SubmissionAccessPolicy $access,
        private AdminProductWriter $products,
        private CatalogSubmissionReviewer $reviews,
        private CatalogSubmissionOwnerEditor $ownerEdits,
        private ProductSearchIndexPort $search,
    ) {
    }

    public function __invoke(UpdateProduct $command): void
    {
        $this->access->authorize(
            CatalogSubmissionKind::Product,
            $command->slug,
            $command->actorId,
            $command->actorIsSuperAdmin,
        );

        $this->products->update($command->slug, $command->draft, $command->actorId, $command->actorIsSuperAdmin);

        if ($command->actorIsSuperAdmin) {
            $this->reviews->reviewerSave(CatalogSubmissionKind::Product, $command->slug, $command->actorId);
        } else {
            $this->ownerEdits->ownerModified(CatalogSubmissionKind::Product, $command->slug);
        }

        // Best-effort: the index follows what the save left publicly readable (ECO-008).
        $this->search->sync($command->slug);
    }
}
