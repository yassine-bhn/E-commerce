<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use PHPUnit\Framework\TestCase;

/**
 * spec-run-17 WP2 / R9, R10, R12 — the two pricing fields are admin-only projections.
 *
 * `toApi()` must never grow the keys, while the admin projection carries them as a number
 * or an explicit null so the back office can display "unset" distinctly.
 */
final class StorefrontProductViewPricingTest extends TestCase
{
    public function testTheAdminProjectionCarriesTheStoredPricingFields(): void
    {
        $view = StorefrontProductView::fromRow($this->row([
            'vat_rate' => '19.50',
            'sale_price_ttc_minor' => 12345,
        ]));

        self::assertSame(19.5, $view->vatRate);
        self::assertSame(12345, $view->salePriceTtc);
        self::assertSame(19.5, $view->toAdminApi()['vatRate']);
        self::assertSame(12345, $view->toAdminApi()['salePriceTtc']);
    }

    public function testTheAdminProjectionCarriesAnExplicitNullWhenThePricingFieldsAreUnset(): void
    {
        $view = StorefrontProductView::fromRow($this->row());

        self::assertNull($view->vatRate);
        self::assertNull($view->salePriceTtc);
        self::assertArrayHasKey('vatRate', $view->toAdminApi());
        self::assertNull($view->toAdminApi()['vatRate']);
        self::assertArrayHasKey('salePriceTtc', $view->toAdminApi());
        self::assertNull($view->toAdminApi()['salePriceTtc']);
    }

    public function testThePublicProjectionHasNoPricingKeysOrValues(): void
    {
        $payload = StorefrontProductView::fromRow($this->row([
            'vat_rate' => '19.50',
            'sale_price_ttc_minor' => 12345,
        ]))->toApi();

        self::assertArrayNotHasKey('vatRate', $payload);
        self::assertArrayNotHasKey('vat_rate', $payload);
        self::assertArrayNotHasKey('salePriceTtc', $payload);
        self::assertArrayNotHasKey('sale_price_ttc_minor', $payload);
        self::assertStringNotContainsString('19.5', (string) json_encode($payload));
        self::assertStringNotContainsString('12345', (string) json_encode($payload));
    }

    public function testFromRowFallsBackToNullWhenThePricingColumnsAreAbsent(): void
    {
        $row = $this->row();
        unset($row['vat_rate'], $row['sale_price_ttc_minor']);

        $view = StorefrontProductView::fromRow($row);
        self::assertNull($view->vatRate);
        self::assertNull($view->salePriceTtc);
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
            'slug' => 'it-pricing-product',
            'name' => 'Produit pricing',
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
            'vat_rate' => null,
            'sale_price_ttc_minor' => null,
        ], $overrides);
    }
}
