<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Port\CatalogSubmissionStore;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\CategoryAlreadyExistsException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes the new category row (spec R1). The handler is the transaction boundary
 * (CMD-005): the row is inserted once, visible by default, and a slug that is already
 * taken leaves nothing behind.
 *
 * spec-run-8 R2/R10/R39 — the row lands as a `PENDING_APPROVAL` submission owned by its creator,
 * and the creation is audited in the same transaction, so no category can appear without its
 * audit row and none can be created approved.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class CreateCategoryHandler
{
    private const SQL = 'INSERT INTO storefront_category (slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug)
         VALUES (:slug, :label, :subs, :quote, :hidden, :ord, NULL)';

    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogSubmissionStore $submissions,
    ) {
    }

    public function __invoke(CreateCategory $command): void
    {
        $category = Category::createRoot(
            slug: $command->slug,
            label: $command->label,
            subcategories: $command->subcategories,
            quoteEligible: $command->quoteEligible,
        );

        $this->connection->transactional(function () use ($category, $command): void {
            $sortOrder = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sort_order), 0) FROM storefront_category') + 1;

            try {
                $this->connection->executeStatement(
                    self::SQL,
                    [
                        'slug' => $category->slug,
                        'label' => $category->label,
                        'subs' => json_encode($category->subcategories(), JSON_THROW_ON_ERROR),
                        'quote' => $category->quoteEligible,
                        'hidden' => $category->isHidden(),
                        'ord' => $sortOrder,
                    ],
                    ['quote' => ParameterType::BOOLEAN, 'hidden' => ParameterType::BOOLEAN],
                );
            } catch (UniqueConstraintViolationException) {
                throw CategoryAlreadyExistsException::forSlug($category->slug);
            }

            $this->submissions->create(
                CatalogSubmissionKind::Category,
                $category->slug,
                $command->actorId ?? 'system',
            );
        });
    }
}
