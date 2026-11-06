<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Port\AdminProductWriter;
use App\Shared\Application\Port\ProductSearchIndexPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.11 — the product create: the taxonomy is validated here, where the row is
 * written (a pending submission cannot hang off an unknown category), and the search index is
 * refreshed best-effort because a pending submission is not public.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class CreateProductHandler
{
    public function __construct(
        private AdminProductWriter $products,
        private ProductSearchIndexPort $search,
    ) {
    }

    public function __invoke(CreateProduct $command): void
    {
        $draft = $command->draft;

        $this->products->create($draft, $command->actorId);

        // Best-effort: a pending submission is not public, so this removes any stale document.
        $this->search->sync($draft->slug);
    }
}
