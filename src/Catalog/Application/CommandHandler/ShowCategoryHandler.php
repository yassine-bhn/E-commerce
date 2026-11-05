<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\ShowCategory;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Application\Port\CategoryVisibilityWriter;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Makes a hidden category visible on the storefront again (spec R5): the same single
 * flag as hiding, so un-hiding cannot resurrect anything except the category itself.
 *
 * spec-run-8 R35 — same ownership rule as hiding: your own submission, or a Super Admin.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ShowCategoryHandler
{
    public function __construct(
        private readonly CategoryReader $categories,
        private readonly CategoryVisibilityWriter $visibility,
        private readonly SubmissionAccessPolicy $access,
    ) {
    }

    public function __invoke(ShowCategory $command): void
    {
        $this->access->authorize(
            CatalogSubmissionKind::Category,
            $command->slug,
            $command->actorId,
            $command->actorIsSuperAdmin,
        );

        $category = $this->categories->find($command->slug);
        if (null === $category) {
            throw CategoryNotFoundException::forSlug($command->slug);
        }

        $this->visibility->setHidden($category->slug, $category->show()->isHidden());
    }
}
