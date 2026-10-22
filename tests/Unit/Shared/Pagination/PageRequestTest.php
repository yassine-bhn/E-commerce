<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Pagination;

use App\Shared\Application\Pagination\PageRequest;
use PHPUnit\Framework\TestCase;

final class PageRequestTest extends TestCase
{
    public function testDefaults(): void
    {
        $request = new PageRequest();

        self::assertSame(1, $request->effectivePage());
        self::assertSame(20, $request->effectiveSize());
        self::assertSame(0, $request->effectiveOffset());
        self::assertNull($request->maxSize);
    }

    public function testPageBelowOneIsClamped(): void
    {
        self::assertSame(1, (new PageRequest(0))->effectivePage());
        self::assertSame(1, (new PageRequest(-5))->effectivePage());
    }

    public function testSizeIsClampedToMaximum(): void
    {
        self::assertSame(100, (new PageRequest(1, 5000))->effectiveSize());
    }

    public function testSizeBelowOneIsClamped(): void
    {
        self::assertSame(1, (new PageRequest(1, 0))->effectiveSize());
        self::assertSame(1, (new PageRequest(1, -3))->effectiveSize());
    }

    public function testPerEndpointMaximumIsHonoured(): void
    {
        self::assertSame(50, (new PageRequest(1, 5000, 50))->effectiveSize());
        self::assertSame(50, (new PageRequest(1, 50, 50))->effectiveSize());
        self::assertSame(200, (new PageRequest(1, 1000, 200))->effectiveSize());
        self::assertSame(24, (new PageRequest(1, 100, 24))->effectiveSize());
    }

    public function testExposesMaxSizeField(): void
    {
        self::assertSame(50, (new PageRequest(1, 24, 50))->maxSize);
    }

    public function testOffsetComputation(): void
    {
        self::assertSame(0, (new PageRequest(1, 24))->effectiveOffset());
        self::assertSame(24, (new PageRequest(2, 24))->effectiveOffset());
        self::assertSame(48, (new PageRequest(3, 24))->effectiveOffset());
    }

    public function testOffsetUsesClampedValues(): void
    {
        self::assertSame(0, (new PageRequest(0, 5000))->effectiveOffset());
        self::assertSame(100, (new PageRequest(2, 5000))->effectiveOffset());
    }
}
