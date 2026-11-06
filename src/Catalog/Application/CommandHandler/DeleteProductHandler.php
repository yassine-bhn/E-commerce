<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\DeleteProduct;
use App\Catalog\Application\Port\AdminProductWriter;
use App\Catalog\Application\Port\CatalogSubmissionDeleter;
use App\Catalog\Application\Port\ProductDeletionReader;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\ProductHasOrdersCannotBeDeletedException;
use App\Shared\Application\Port\ProductSearchIndexPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.11 — spec-run-8 R19/R20/R42: deletion keeps its order-history guard, never
 * touches the approval decision, and the store removes the whole record (balance row, view row,
 * submission row) in the command-bus transaction.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteProductHandler
{
    public function __construct(
        private ProductDeletionReader $deletionReads,
        private AdminProductWriter $products,
        private CatalogSubmissionDeleter $submissions,
        private ProductSearchIndexPort $search,
    ) {
    }

    public function __invoke(DeleteProduct $command): void
    {
        $orderCount = $this->deletionReads->orderCount($command->slug);
        if ($orderCount > 0) {
            throw ProductHasOrdersCannotBeDeletedException::forProduct($command->slug, $orderCount);
        }

        $this->products->delete($command->slug, $command->actorId);

        // Slug reuse must not inherit a previous approval (R11).
        $this->submissions->delete(CatalogSubmissionKind::Product, $command->slug);

        // Best-effort: the read model stays authoritative if Meilisearch is down (ECO-008).
        $this->search->forget($command->slug);
    }
}
