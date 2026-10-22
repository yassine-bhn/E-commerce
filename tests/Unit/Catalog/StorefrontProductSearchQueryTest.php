<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Query\StorefrontProductFilters;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchQuery;
use PHPUnit\Framework\TestCase;

/** R4 — the Meilisearch `filter`/`sort` expressions built from the storefront query parameters. */
final class StorefrontProductSearchQueryTest extends TestCase
{
    public function testNoFilterIsSentWhenNothingIsRequested(): void
    {
        self::assertNull(StorefrontProductSearchQuery::filter(new StorefrontProductFilters()));
    }

    public function testEveryFilterIsCombinedWithAnd(): void
    {
        $filter = StorefrontProductSearchQuery::filter(new StorefrontProductFilters(
            q: 'chaise',
            categorySlug: 'chaises',
            subcategory: 'Chaises de bureau',
            brand: 'Zidane',
            isNew: true,
            isBest: false,
            priceMin: 100000,
            priceMax: 500000,
            material: 'Acier',
            stock: 'in',
        ));

        self::assertSame(
            'category_slug = "chaises" AND subcategory = "Chaises de bureau" AND brand = "Zidane"'
            .' AND is_new = true AND is_best = false AND price_minor >= 100000 AND price_minor <= 500000'
            .' AND material = "Acier" AND stock = "in"',
            $filter,
        );
    }

    public function testPromotionsOnlyUsesNoIndexFilter(): void
    {
        self::assertNull(
            StorefrontProductSearchQuery::filter(new StorefrontProductFilters(promotionsOnly: true)),
            'the promotion flag is a SQL-only filter: old_price_minor is not filterable in the index',
        );
    }

    public function testQuotesAndBackslashesAreEscaped(): void
    {
        $filter = StorefrontProductSearchQuery::filter(new StorefrontProductFilters(brand: 'Zi"dane\\Groupe'));

        self::assertSame('brand = "Zi\\"dane\\\\Groupe"', $filter);
    }

    public function testTheDocumentedSortWhitelistIsMapped(): void
    {
        self::assertSame(['name:asc'], StorefrontProductSearchQuery::sort(null), 'name is the documented default');
        self::assertSame(['name:asc'], StorefrontProductSearchQuery::sort('name'));
        self::assertSame(['price_minor:asc'], StorefrontProductSearchQuery::sort('price_asc'));
        self::assertSame(['price_minor:desc'], StorefrontProductSearchQuery::sort('price_desc'));
        self::assertSame(['rating:desc', 'reviews:desc'], StorefrontProductSearchQuery::sort('rating'));
    }

    public function testUnknownSortFallsBackToTheDefault(): void
    {
        self::assertSame(['name:asc'], StorefrontProductSearchQuery::sort('created_at'));
    }

    public function testNewestAndPromotionsCannotBeServedByTheIndex(): void
    {
        self::assertFalse(
            StorefrontProductSearchQuery::indexable(new StorefrontProductFilters(sort: 'newest')),
            'newest relies on is_new, which is filterable but not sortable',
        );
        self::assertFalse(StorefrontProductSearchQuery::indexable(new StorefrontProductFilters(promotionsOnly: true)));
        self::assertTrue(StorefrontProductSearchQuery::indexable(new StorefrontProductFilters(categorySlug: 'chaises', sort: 'price_asc')));
    }
}
