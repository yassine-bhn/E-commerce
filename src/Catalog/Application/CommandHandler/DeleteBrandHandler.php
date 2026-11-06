<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\DeleteBrand;
use App\Catalog\Application\Port\BrandReader;
use App\Catalog\Application\Port\BrandStore;
use App\Catalog\Domain\Model\BrandNotEmptyException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.9 — deleting a catalog record is a Super Admin decision, and name reuse must not
 * inherit a previous approval (R11). The store owns the whole workflow: refusal on a brand that
 * still carries products, the row delete, the submission removal and the audit entry.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteBrandHandler
{
    public function __construct(
        private BrandReader $brands,
        private BrandStore $store,
    ) {
    }

    public function __invoke(DeleteBrand $command): void
    {
        $productCount = $this->brands->countProducts($command->name);
        if ($productCount > 0) {
            throw BrandNotEmptyException::forBrand($command->name, $productCount);
        }

        $this->store->delete($command->name, $command->actorId);
    }
}
