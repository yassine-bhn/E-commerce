<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\AddCategorySubLevel;
use App\Catalog\Domain\Model\Category;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Adds one node to the `subcategories` JSON tree of the parent row (spec R2, ADR-0006):
 * no child row is created, and a refused label leaves the parent exactly as it was.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class AddCategorySubLevelHandler
{
    private const LOAD = 'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug
         FROM storefront_category WHERE slug = :slug';

    private const STORE = 'UPDATE storefront_category SET subcategories = :subs WHERE slug = :slug';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(AddCategorySubLevel $command): void
    {
        $category = Category::fromStoredRow(
            $this->connection->fetchAssociative(self::LOAD, ['slug' => $command->slug]),
            $command->slug,
        )->withSubLevel($command->label, $command->parentLabel);

        $this->connection->executeStatement(
            self::STORE,
            ['subs' => $category->subcategoriesJson(), 'slug' => $category->slug],
        );
    }
}
