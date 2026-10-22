<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Pagination;

use App\Shared\Application\Pagination\CursorRequest;
use PHPUnit\Framework\TestCase;

final class CursorRequestTest extends TestCase
{
    public function testDefaults(): void
    {
        $request = new CursorRequest();

        self::assertSame(20, $request->effectiveLimit());
        self::assertNull($request->rawCursor());
        self::assertNull($request->maxLimit);
    }

    public function testLimitIsClampedToMaximum(): void
    {
        self::assertSame(100, (new CursorRequest(5000))->effectiveLimit());
    }

    public function testLimitBelowOneIsClamped(): void
    {
        self::assertSame(1, (new CursorRequest(0))->effectiveLimit());
        self::assertSame(1, (new CursorRequest(-10))->effectiveLimit());
    }

    public function testPerEndpointMaximumIsHonoured(): void
    {
        self::assertSame(50, (new CursorRequest(5000, null, 50))->effectiveLimit());
        self::assertSame(10, (new CursorRequest(10, null, 10))->effectiveLimit());
    }

    public function testBlankCursorIsNull(): void
    {
        self::assertNull((new CursorRequest(20, ''))->rawCursor());
        self::assertNull((new CursorRequest(20, '   '))->rawCursor());
        self::assertNull((new CursorRequest(20, null))->rawCursor());
    }

    public function testNonBlankCursorIsReturned(): void
    {
        self::assertSame('abc', (new CursorRequest(20, 'abc'))->rawCursor());
    }
}
