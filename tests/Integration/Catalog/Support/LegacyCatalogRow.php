<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The row shape R41 promises to preserve: a catalogue product and its category that existed before
 * the approval migration, stored as approved and published.
 *
 * The rollback test writes them into the probe after the full up() chain, migrates down, and expects
 * to find them untouched — only the approval columns may disappear, never a catalogue row. The
 * columns come from {@see CatalogRow}, which owns that list; this class only states what makes the
 * row legacy.
 */
final class LegacyCatalogRow
{
    public const string PRODUCT_NAME = 'Produit hérité du catalogue';
    public const string CATEGORY_LABEL = 'Catégorie héritée';

    public static function seed(Connection $connection, string $productSlug, string $categorySlug): void
    {
        CatalogRow::category($connection, $categorySlug, 'APPROVED', [
            'label' => self::CATEGORY_LABEL,
            'sort_order' => 900,
        ]);

        CatalogRow::product($connection, $productSlug, 'APPROVED', [
            // Derived from the slug, so a rerun of the fixture can never insert a second row for it.
            'id' => Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'it:legacy-product:'.$productSlug)->toRfc4122(),
            'name' => self::PRODUCT_NAME,
            'ref' => 'IT-LEGACY',
            'category_slug' => $categorySlug,
            'image_path' => '/uploads/products/it-legacy.jpg',
            'description' => 'Produit présent avant la migration d\'approbation.',
            'published' => true,
        ]);
    }
}
