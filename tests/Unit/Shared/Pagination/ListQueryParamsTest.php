<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Pagination;

use App\Shared\Application\Pagination\Cursor;
use App\Shared\Application\Pagination\CursorRequest;
use App\Shared\Application\Pagination\PageRequest;
use App\Shared\Infrastructure\Http\ListQueryParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListQueryParamsTest extends TestCase
{
    public function testPageClampsOutOfRangeValues(): void
    {
        $request = Request::create('/x?page=0&size=5000&q=%20&cursor=');

        $page = ListQueryParams::page($request);

        self::assertInstanceOf(PageRequest::class, $page);
        self::assertSame(1, $page->effectivePage());
        self::assertSame(100, $page->effectiveSize());
        self::assertSame(0, $page->effectiveOffset());
    }

    public function testPageReadsValuesAndHonoursPerEndpointMaximum(): void
    {
        $request = Request::create('/x?page=3&size=5000');

        $page = ListQueryParams::page($request, 50, 200);

        self::assertSame(3, $page->effectivePage());
        self::assertSame(200, $page->effectiveSize());
        self::assertSame(400, $page->effectiveOffset());
    }

    public function testPageUsesDefaultSizeWhenAbsent(): void
    {
        $request = Request::create('/x');

        $page = ListQueryParams::page($request, 24);

        self::assertSame(1, $page->effectivePage());
        self::assertSame(24, $page->effectiveSize());
    }

    public function testCursorReadsBlankCursorAsNull(): void
    {
        $request = Request::create('/x?page=0&size=5000&q=%20&cursor=');

        $cursor = ListQueryParams::cursor($request);

        self::assertInstanceOf(CursorRequest::class, $cursor);
        self::assertNull($cursor->rawCursor());
        self::assertSame(20, $cursor->effectiveLimit());
    }

    public function testCursorReadsValuesAndClamps(): void
    {
        $raw = Cursor::encode('2026-10-20T11:00:00Z', '019a-...');
        $request = Request::create('/x?limit=5000&cursor='.rawurlencode($raw));

        $cursor = ListQueryParams::cursor($request);

        self::assertSame($raw, $cursor->rawCursor());
        self::assertSame(100, $cursor->effectiveLimit());
    }

    public function testCursorHonoursPerEndpointMaximumAndDefaultLimit(): void
    {
        $request = Request::create('/x');

        $cursor = ListQueryParams::cursor($request, 10, 50);

        self::assertSame(10, $cursor->effectiveLimit());
    }

    public function testTextReturnsTrimmedValueOrNullWhenBlank(): void
    {
        self::assertNull(ListQueryParams::text(Request::create('/x?q=%20')));
        self::assertNull(ListQueryParams::text(Request::create('/x')));
        self::assertSame('chaise', ListQueryParams::text(Request::create('/x?q=%20chaise%20')));
        self::assertSame('chaise', ListQueryParams::text(Request::create('/x?term=chaise'), 'term'));
    }

    public function testIntOrNull(): void
    {
        self::assertNull(ListQueryParams::intOrNull(Request::create('/x'), 'min'));
        self::assertSame(10, ListQueryParams::intOrNull(Request::create('/x?min=10'), 'min'));
        self::assertSame(0, ListQueryParams::intOrNull(Request::create('/x?min=0'), 'min'));
        self::assertNull(ListQueryParams::intOrNull(Request::create('/x?min=abc'), 'min'));
    }

    public function testBoolOrNullDistinguishesAbsentFromFalse(): void
    {
        self::assertNull(ListQueryParams::boolOrNull(Request::create('/x'), 'active'));
        self::assertFalse(ListQueryParams::boolOrNull(Request::create('/x?active=false'), 'active'));
        self::assertTrue(ListQueryParams::boolOrNull(Request::create('/x?active=true'), 'active'));
    }

    public function testDateOrNullAcceptsOnlyStrictCalendarDates(): void
    {
        self::assertNull(ListQueryParams::dateOrNull(Request::create('/x'), 'dateFrom'));
        self::assertNull(ListQueryParams::dateOrNull(Request::create('/x?dateFrom=%20'), 'dateFrom'));
        self::assertNull(ListQueryParams::dateOrNull(Request::create('/x?dateFrom=2026-13-45'), 'dateFrom'));
        self::assertNull(ListQueryParams::dateOrNull(Request::create('/x?dateFrom=01/10/2026'), 'dateFrom'));
        self::assertNull(ListQueryParams::dateOrNull(Request::create('/x?dateFrom=2026-02-30'), 'dateFrom'));

        $date = ListQueryParams::dateOrNull(Request::create('/x?dateFrom=2026-10-01'), 'dateFrom');
        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('2026-10-01', $date->format('Y-m-d'));
        self::assertSame('00:00:00', $date->format('H:i:s'), 'a date filter carries no time of day');
    }
}
