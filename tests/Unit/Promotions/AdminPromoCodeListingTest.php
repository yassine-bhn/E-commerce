<?php

declare(strict_types=1);

namespace App\Tests\Unit\Promotions;

use App\Promotions\Application\Query\ListAdminPromoCodes;
use App\Shared\Application\Pagination\PageRequest;
use PHPUnit\Framework\TestCase;

/**
 * R12 — pagination bounds of the admin promo-code list: `size` is capped at 200 and `page`
 * never falls below one, while the filters stay optional.
 */
final class AdminPromoCodeListingTest extends TestCase
{
    public function testThePromoCodeWindowIsBounded(): void
    {
        $query = new ListAdminPromoCodes(new PageRequest(0, 5000, ListAdminPromoCodes::MAX_SIZE));
        self::assertSame(1, $query->page->effectivePage());
        self::assertSame(200, $query->page->effectiveSize());
        self::assertSame(0, $query->page->effectiveOffset());
        self::assertSame(50, (new ListAdminPromoCodes())->page->effectiveSize(), 'the default page is 50');

        self::assertNull($query->q);
        self::assertNull($query->active);
    }
}
