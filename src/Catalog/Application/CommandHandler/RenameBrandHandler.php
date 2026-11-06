<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\RenameBrand;
use App\Catalog\Application\Port\BrandStore;
use App\Shared\Application\Port\ProductSearchIndexPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.9 — a rename is the one write that changes the record's identity: the brand row,
 * every product carrying the name and the submission row move together, and each renamed product
 * is re-indexed (ECO-008).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RenameBrandHandler
{
    public function __construct(
        private BrandStore $store,
        private ProductSearchIndexPort $search,
    ) {
    }

    public function __invoke(RenameBrand $command): void
    {
        if ($command->new === $command->old) {
            return; // a no-op rename writes nothing and re-indexes nothing
        }

        $slugs = $this->store->rename($command->old, $command->new, $command->actorId, $command->actorIsSuperAdmin);

        foreach ($slugs as $slug) {
            $this->search->sync($slug);
        }
    }
}
