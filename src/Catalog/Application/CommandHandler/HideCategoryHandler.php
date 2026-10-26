<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\HideCategory;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\Category;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Hides a category from storefront navigation (spec R5).
 *
 * Only the visibility flag is written: the row, its sub-level tree and its products stay
 * in place, so hiding is always reversible and never destroys catalog data.
 *
 * spec-run-8 R35 — hiding goes through the same write guard as every other category edit: the
 * back office may hide any row, and hiding an approved one sends it back for review.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class HideCategoryHandler
{
    private const LOAD = 'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug
         FROM storefront_category WHERE slug = :slug';

    private const STORE = 'UPDATE storefront_category SET hidden = :hidden WHERE slug = :slug';

    public function __construct(
        private readonly Connection $connection,
        private readonly SubmissionAccessPolicy $access,
    ) {
    }

    public function __invoke(HideCategory $command): void
    {
        $this->connection->transactional(function () use ($command): void {
            $this->access->authorize(
                CatalogSubmissionKind::Category,
                $command->slug,
                $command->actorId,
                $command->actorIsAdmin,
            );

            $category = Category::fromStoredRow(
                $this->connection->fetchAssociative(self::LOAD, ['slug' => $command->slug]),
                $command->slug,
            )->hide();

            $this->connection->executeStatement(
                self::STORE,
                ['hidden' => $category->isHidden(), 'slug' => $category->slug],
                ['hidden' => ParameterType::BOOLEAN],
            );
        });
    }
}
