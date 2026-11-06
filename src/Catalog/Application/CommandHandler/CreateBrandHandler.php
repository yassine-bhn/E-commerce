<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\CreateBrand;
use App\Catalog\Application\Port\BrandReader;
use App\Catalog\Application\Port\BrandStore;
use App\Catalog\Application\Port\CatalogSubmissionCreator;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Domain\Model\BrandAlreadyExistsException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.9 — creating a brand is approved at once (the same rule as a category creation),
 * so the storefront filters can offer it without a second decision.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class CreateBrandHandler
{
    public function __construct(
        private BrandReader $brands,
        private BrandStore $store,
        private CatalogSubmissionCreator $submissions,
        private CatalogSubmissionReviewer $reviews,
    ) {
    }

    public function __invoke(CreateBrand $command): void
    {
        if ($this->brands->exists($command->name)) {
            throw BrandAlreadyExistsException::forName($command->name);
        }

        $this->store->create($command->name);

        $this->submissions->create(CatalogSubmissionKind::Brand, $command->name, $command->actorId);
        $this->reviews->reviewerSave(CatalogSubmissionKind::Brand, $command->name, $command->actorId);
    }
}
