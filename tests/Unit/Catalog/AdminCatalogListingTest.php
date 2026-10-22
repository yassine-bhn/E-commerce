<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Query\ListAdminBrands;
use App\Catalog\Application\Query\ListCategories;
use App\Shared\Application\Pagination\PageRequest;
use PHPUnit\Framework\TestCase;

/**
 * R10/R11 — pagination bounds and sort vocabulary of the two admin catalog lists: `size` is
 * capped at 200, `page` never falls below one and an unknown sort falls back to the documented default.
 */
final class AdminCatalogListingTest extends TestCase
{
    public function testTheCategoryWindowIsBoundedAndTheSortHasAFallback(): void
    {
        $query = new ListCategories('requester', true, new PageRequest(0, 5000, ListCategories::MAX_SIZE));
        self::assertSame(1, $query->page->effectivePage());
        self::assertSame(200, $query->page->effectiveSize());
        self::assertSame(0, $query->page->effectiveOffset());
        self::assertSame(50, (new ListCategories('requester', true))->page->effectiveSize(), 'the default page is 50');

        self::assertSame('sortOrder', $query->effectiveSort());
        self::assertSame('sortOrder', (new ListCategories('requester', true, sort: 'inconnu'))->effectiveSort());
        self::assertSame('label', (new ListCategories('requester', true, sort: 'label'))->effectiveSort());
        self::assertSame('productCount', (new ListCategories('requester', true, sort: 'productCount'))->effectiveSort());
    }

    public function testTheBrandWindowIsBoundedAndTheSortFallsBackToName(): void
    {
        $query = new ListAdminBrands(new PageRequest(4, 0, ListAdminBrands::MAX_SIZE));
        self::assertSame(4, $query->page->effectivePage());
        self::assertSame(1, $query->page->effectiveSize());
        self::assertSame(3, $query->page->effectiveOffset());
        self::assertSame(50, (new ListAdminBrands())->page->effectiveSize(), 'the default page is 50');

        self::assertSame('name', $query->effectiveSort());
        self::assertSame('name', (new ListAdminBrands(sort: 'inconnu'))->effectiveSort());
        self::assertSame('productCount', (new ListAdminBrands(sort: 'productCount'))->effectiveSort());
    }
}
