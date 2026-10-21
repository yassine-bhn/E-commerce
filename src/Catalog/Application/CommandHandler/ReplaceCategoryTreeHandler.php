<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\ReplaceCategoryTree;
use App\Catalog\Domain\Model\Category;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rewrites the editable content of a category (spec R3): label, quote eligibility and the
 * whole `subcategories` JSON tree of that row.
 *
 * Visibility is deliberately not part of this statement — hiding is its own command — and
 * a tree that would drop a sub-level a product still points at is refused before anything
 * is written, so the catalog can never disagree with an order snapshot (ECO-006).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ReplaceCategoryTreeHandler
{
    private const LOAD = 'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug
         FROM storefront_category WHERE slug = :slug';

    private const USED_SUB_LEVELS = "SELECT DISTINCT subcategory
         FROM storefront_product_view
         WHERE category_slug = :slug
           AND subcategory IS NOT NULL
           AND subcategory <> ''
         ORDER BY subcategory ASC";

    private const STORE = 'UPDATE storefront_category SET label = :label, quote_eligible = :quote, subcategories = :subs WHERE slug = :slug';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(ReplaceCategoryTree $command): void
    {
        $category = Category::fromStoredRow(
            $this->connection->fetchAssociative(self::LOAD, ['slug' => $command->slug]),
            $command->slug,
        )->withContent($command->label, $command->subcategories, $command->quoteEligible);

        $category->coversSubLevels($this->usedSubLevels($category->slug));

        $this->connection->executeStatement(
            self::STORE,
            [
                'label' => $category->label,
                'quote' => $category->quoteEligible,
                'subs' => $category->subcategoriesJson(),
                'slug' => $category->slug,
            ],
            ['quote' => ParameterType::BOOLEAN],
        );
    }

    /**
     * The sub-level labels the products of this category currently point at.
     *
     * @return list<string>
     */
    private function usedSubLevels(string $slug): array
    {
        $labels = [];
        foreach ($this->connection->fetchAllAssociative(self::USED_SUB_LEVELS, ['slug' => $slug]) as $row) {
            $label = trim((string) ($row['subcategory'] ?? ''));
            if ('' !== $label) {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }
}
