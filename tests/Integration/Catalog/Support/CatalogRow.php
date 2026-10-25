<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Support;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Raw rows for the two catalog tables the approval workflow writes.
 *
 * The tests that use it exercise storage rules (CHECK constraints, transaction boundaries), so they
 * need a persisted row before any application code runs — the same raw-SQL fixture style as the
 * other catalog integration tests.
 */
final class CatalogRow
{
    private const string STATUS_APPROVED = 'APPROVED';

    /**
     * @param array<string, mixed> $overrides Column values a caller needs to differ from the fixture
     *                                        defaults — a legacy row, a row of another category — so
     *                                        the column list lives here once instead of in every fixture
     */
    public static function product(
        Connection $connection,
        string $slug,
        string $approvalStatus = self::STATUS_APPROVED,
        array $overrides = [],
    ): void {
        $connection->insert(
            'storefront_product_view',
            array_replace(
                [
                    // Derived from the slug, so a re-run can never insert a second row for it.
                    'id' => Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'it:product:'.$slug)->toRfc4122(),
                    'slug' => $slug,
                    'name' => 'Produit de contrainte '.$slug,
                    'brand' => 'Sopal',
                    'ref' => 'IT-'.strtoupper(substr($slug, -6)),
                    'category_slug' => 'it-status-constraint',
                    'subcategory' => 'Sanitaires',
                    'price_minor' => 12_000,
                    'old_price_minor' => null,
                    'currency' => 'TND',
                    'stock' => 'in',
                    'material' => 'Acier',
                    'dims' => '10 x 20 cm',
                    'image_path' => '/uploads/products/it-constraint.jpg',
                    'rating' => 4.5,
                    'reviews' => 3,
                    'is_new' => false,
                    'is_best' => false,
                    'description' => 'Produit créé par un test d\'intégration.',
                    'approval_status' => $approvalStatus,
                    'published' => false,
                ],
                $overrides,
            ),
            [
                'rating' => ParameterType::STRING,
                'reviews' => ParameterType::INTEGER,
                'price_minor' => ParameterType::INTEGER,
                'is_new' => ParameterType::BOOLEAN,
                'is_best' => ParameterType::BOOLEAN,
                'published' => ParameterType::BOOLEAN,
            ],
        );
    }

    /**
     * @param array<string, mixed> $overrides See {@see self::product()}
     */
    public static function category(
        Connection $connection,
        string $slug,
        string $approvalStatus = self::STATUS_APPROVED,
        array $overrides = [],
    ): void {
        $connection->insert(
            'storefront_category',
            array_replace(
                [
                    'slug' => $slug,
                    'label' => 'Catégorie de contrainte',
                    'subcategories' => '[]',
                    'quote_eligible' => false,
                    'sort_order' => 500,
                    'parent_slug' => null,
                    'hidden' => false,
                    'approval_status' => $approvalStatus,
                ],
                $overrides,
            ),
            [
                'quote_eligible' => ParameterType::BOOLEAN,
                'hidden' => ParameterType::BOOLEAN,
                'sort_order' => ParameterType::INTEGER,
            ],
        );
    }

    public static function deleteProducts(Connection $connection, string ...$slugs): void
    {
        $connection->executeStatement(
            'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
            ['slugs' => $slugs],
            ['slugs' => ArrayParameterType::STRING],
        );
    }

    public static function deleteCategories(Connection $connection, string ...$slugs): void
    {
        $connection->executeStatement(
            'DELETE FROM storefront_category WHERE slug IN (:slugs)',
            ['slugs' => $slugs],
            ['slugs' => ArrayParameterType::STRING],
        );
    }

    public static function deleteAuditEntries(Connection $connection, string ...$targetSlugs): void
    {
        $connection->executeStatement(
            'DELETE FROM catalog_audit_log WHERE target_slug IN (:slugs)',
            ['slugs' => $targetSlugs],
            ['slugs' => ArrayParameterType::STRING],
        );
    }
}
