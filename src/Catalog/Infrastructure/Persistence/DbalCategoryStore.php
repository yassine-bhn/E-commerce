<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Application\Port\CategoryCreator;
use App\Catalog\Application\Port\CategoryDeleter;
use App\Catalog\Application\Port\CategoryDeletionReader;
use App\Catalog\Application\Port\CategoryHierarchyReader;
use App\Catalog\Application\Port\CategoryReader;
use App\Catalog\Application\Port\CategoryTreeWriter;
use App\Catalog\Application\Port\CategoryVisibilityWriter;
use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\CategoryAlreadyExistsException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * DIP-003 — the one DBAL adapter behind the category ports; the SQL is the handlers' and the
 * hierarchy validator's former inline statements, moved verbatim.
 */
final class DbalCategoryStore implements CategoryReader, CategoryCreator, CategoryTreeWriter, CategoryVisibilityWriter, CategoryDeleter, CategoryDeletionReader, CategoryHierarchyReader
{
    private const LOAD = 'SELECT slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug
         FROM storefront_category WHERE slug = :slug';

    private const INSERT = 'INSERT INTO storefront_category (slug, label, subcategories, quote_eligible, hidden, sort_order, parent_slug)
         VALUES (:slug, :label, :subs, :quote, :hidden, :ord, NULL)';

    private const USED_SUB_LEVELS = "SELECT DISTINCT subcategory
         FROM storefront_product_view
         WHERE category_slug = :slug
           AND subcategory IS NOT NULL
           AND subcategory <> ''
         ORDER BY subcategory ASC";

    public function __construct(private Connection $connection)
    {
    }

    public function find(string $slug): ?Category
    {
        $row = $this->connection->fetchAssociative(self::LOAD, ['slug' => $slug]);

        return false === $row ? null : Category::fromStoredRow($row, $slug);
    }

    public function usedSubLevels(string $slug): array
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

    private function nextSortOrder(): int
    {
        return (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sort_order), 0) FROM storefront_category') + 1;
    }

    public function create(Category $category): void
    {
        try {
            $this->connection->executeStatement(
                self::INSERT,
                [
                    'slug' => $category->slug,
                    'label' => $category->label,
                    'subs' => json_encode($category->subcategories(), JSON_THROW_ON_ERROR),
                    'quote' => $category->quoteEligible,
                    'hidden' => $category->isHidden(),
                    'ord' => $this->nextSortOrder(),
                ],
                ['quote' => ParameterType::BOOLEAN, 'hidden' => ParameterType::BOOLEAN],
            );
        } catch (UniqueConstraintViolationException) {
            throw CategoryAlreadyExistsException::forSlug($category->slug);
        }
    }

    public function replaceContent(Category $category): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_category SET label = :label, quote_eligible = :quote, subcategories = :subs WHERE slug = :slug',
            [
                'label' => $category->label,
                'quote' => $category->quoteEligible,
                'subs' => $category->subcategoriesJson(),
                'slug' => $category->slug,
            ],
            ['quote' => ParameterType::BOOLEAN],
        );
    }

    public function addSubLevel(Category $category): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_category SET subcategories = :subs WHERE slug = :slug',
            ['subs' => $category->subcategoriesJson(), 'slug' => $category->slug],
        );
    }

    public function setHidden(string $slug, bool $hidden): void
    {
        $this->connection->executeStatement(
            'UPDATE storefront_category SET hidden = :hidden WHERE slug = :slug',
            ['hidden' => $hidden, 'slug' => $slug],
            ['hidden' => ParameterType::BOOLEAN],
        );
    }

    public function counts(string $slug): array
    {
        $counts = $this->connection->fetchAssociative(
            'SELECT (SELECT COUNT(*) FROM storefront_category WHERE parent_slug = :slug) AS child_rows,
            (SELECT COUNT(*) FROM storefront_product_view WHERE category_slug = :slug) AS products',
            ['slug' => $slug],
        );

        return [
            'children' => (int) ($counts['child_rows'] ?? 0),
            'products' => (int) ($counts['products'] ?? 0),
        ];
    }

    public function delete(string $slug): void
    {
        $this->connection->executeStatement('DELETE FROM storefront_category WHERE slug = :slug', ['slug' => $slug]);
    }

    public function parentMap(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT slug, parent_slug FROM storefront_category');

        $parentMap = [];
        foreach ($rows as $row) {
            $parentMap[(string) $row['slug']] = isset($row['parent_slug']) ? (string) $row['parent_slug'] : null;
        }

        return $parentMap;
    }
}
