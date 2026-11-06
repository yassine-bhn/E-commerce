<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Infrastructure\ReadModel\StorefrontProductView;
use PHPUnit\Framework\TestCase;

/**
 * The public projection carries the gallery pictures after the primary one.
 *
 * `image` always stays the first picture; `additionalImages` is the ordered remainder.
 * Rows coming from projections that do not select the column (legacy queries) must not
 * break: they fall back to an empty gallery.
 */
final class StorefrontProductViewAdditionalImagesTest extends TestCase
{
    public function testThePublicProjectionCarriesTheStoredAdditionalImages(): void
    {
        $view = StorefrontProductView::fromRow($this->row([
            'additional_images' => '["/uploads/catalogue/cbm/ref/02.jpg","/uploads/catalogue/cbm/ref/03.png"]',
        ]));

        self::assertSame('/uploads/catalogue/cbm/ref/01.jpg', $view->imagePath);
        self::assertSame(
            ['/uploads/catalogue/cbm/ref/02.jpg', '/uploads/catalogue/cbm/ref/03.png'],
            $view->toApi()['additionalImages'],
        );
    }

    public function testARowWithoutTheColumnFallsBackToAnEmptyGallery(): void
    {
        $view = StorefrontProductView::fromRow($this->row());

        self::assertSame([], $view->additionalImages);
        self::assertSame([], $view->toApi()['additionalImages']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '00000000-0000-4000-8000-000000000002',
            'slug' => 'it-gallery-product',
            'name' => 'Produit galerie',
            'brand' => 'CBM',
            'ref' => 'REF-002',
            'category_slug' => 'plomberie',
            'subcategory' => 'robinet',
            'price_minor' => 10_000,
            'old_price_minor' => null,
            'currency' => 'TND',
            'stock' => 'in',
            'stock_quantity' => 4,
            'material' => '',
            'dims' => '',
            'image_path' => '/uploads/catalogue/cbm/ref/01.jpg',
            'rating' => 0,
            'reviews' => 0,
            'is_new' => true,
            'is_best' => false,
            'headrest' => false,
            'description' => 'Produit de test.',
            'variants' => '[]',
            'supplier_reference' => 'REF-002',
        ], $overrides);
    }
}
