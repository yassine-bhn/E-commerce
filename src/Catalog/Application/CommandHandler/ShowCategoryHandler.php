<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\ShowCategory;
use App\Catalog\Domain\Model\Category;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Makes a hidden category visible on the storefront again (spec R5): the same single
 * flag as hiding, so un-hiding cannot resurrect anything except the category itself.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ShowCategoryHandler
{
    private const LOAD = 'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug
         FROM storefront_category WHERE slug = :slug';

    private const STORE = 'UPDATE storefront_category SET hidden = :hidden WHERE slug = :slug';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ShowCategory $command): void
    {
        $category = Category::fromStoredRow(
            $this->connection->fetchAssociative(self::LOAD, ['slug' => $command->slug]),
            $command->slug,
        )->show();

        $this->connection->executeStatement(
            self::STORE,
            ['hidden' => $category->isHidden(), 'slug' => $category->slug],
            ['hidden' => ParameterType::BOOLEAN],
        );
    }
}
