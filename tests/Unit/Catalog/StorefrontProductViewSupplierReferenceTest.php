<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use PHPUnit\Framework\TestCase;

/**
 * spec-run-16 WP2 / R2, R8 — the supplier reference is an admin-only projection of the product.
 *
 * The public `toApi()` payload must never grow the key, while the admin projection carries it
 * as a string or an explicit null so the back office can display "unset" distinctly.
 */
final class StorefrontProductViewSupplierReferenceTest extends TestCase
{
    public function testTheAdminProjectionCarriesTheStoredSupplierReference(): void
    {
        $view = StorefrontProductView::fromRow($this->row(['supplier_reference' => 'SUP-42']));

        self::assertSame('SUP-42', $view->supplierReference);
        self::assertSame('SUP-42', $view->toAdminApi()['supplierReference']);
    }

    public function testTheAdminProjectionCarriesAnExplicitNullWhenTheSupplierReferenceIsUnset(): void
    {
        $view = StorefrontProductView::fromRow($this->row());

        self::assertNull($view->supplierReference);
        self::assertArrayHasKey('supplierReference', $view->toAdminApi());
        self::assertNull($view->toAdminApi()['supplierReference']);
    }

    public function testThePublicProjectionHasNoSupplierReferenceKeyOrValue(): void
    {
        $payload = StorefrontProductView::fromRow($this->row(['supplier_reference' => 'SUP-42']))->toApi();

        self::assertArrayNotHasKey('supplierReference', $payload);
        self::assertArrayNotHasKey('supplier_reference', $payload);
        self::assertArrayNotHasKey('supplier-reference', $payload);
        self::assertArrayNotHasKey('supplierRefference', $payload);
        self::assertStringNotContainsString('SUP-42', (string) json_encode($payload));
    }

    public function testFromRowFallsBackToNullWhenTheSupplierReferenceColumnIsAbsent(): void
    {
        $row = $this->row();
        unset($row['supplier_reference']);

        self::assertNull(StorefrontProductView::fromRow($row)->supplierReference);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '00000000-0000-4000-8000-000000000001',
            'slug' => 'it-supplier-reference-product',
            'name' => 'Produit référence fournisseur',
            'brand' => 'Sopal',
            'ref' => 'REF-001',
            'category_slug' => 'sanitaires',
            'subcategory' => 'WC',
            'price_minor' => 10_000,
            'old_price_minor' => null,
            'currency' => 'TND',
            'stock' => 'in',
            'stock_quantity' => 12,
            'material' => 'Céramique',
            'dims' => '50 cm',
            'image_path' => '/p-wc.jpg',
            'rating' => 4.5,
            'reviews' => 3,
            'is_new' => false,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Produit de test.',
            'variants' => '[]',
            'supplier_reference' => null,
        ], $overrides);
    }
}
