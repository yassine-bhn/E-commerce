<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Pagination;

use App\Shared\Application\Pagination\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testToArrayExactShapeForFullPage(): void
    {
        $page = new Page(['a', 'b'], 2, 24, 137);

        self::assertSame(
            [
                'items' => ['a', 'b'],
                'meta' => [
                    'page' => 2,
                    'size' => 24,
                    'total' => 137,
                    'totalPages' => 6,
                    'hasMore' => true,
                ],
            ],
            $page->toArray(),
        );
    }

    public function testToArrayExactShapeForEmptyResult(): void
    {
        $page = new Page([], 1, 20, 0);

        self::assertSame(
            [
                'items' => [],
                'meta' => [
                    'page' => 1,
                    'size' => 20,
                    'total' => 0,
                    'totalPages' => 1,
                    'hasMore' => false,
                ],
            ],
            $page->toArray(),
        );
    }

    public function testTotalPagesRounding(): void
    {
        self::assertSame(1, (new Page([], 1, 20, 0))->totalPages());
        self::assertSame(1, (new Page([], 1, 20, 1))->totalPages());
        self::assertSame(1, (new Page([], 1, 20, 20))->totalPages());
        self::assertSame(2, (new Page([], 1, 20, 21))->totalPages());
        self::assertSame(6, (new Page([], 1, 24, 137))->totalPages());
    }

    public function testHasMore(): void
    {
        self::assertFalse((new Page([], 1, 20, 0))->hasMore());
        self::assertFalse((new Page([], 1, 20, 20))->hasMore());
        self::assertTrue((new Page([], 1, 20, 21))->hasMore());
        self::assertTrue((new Page([], 2, 20, 45))->hasMore());
        self::assertFalse((new Page([], 3, 20, 45))->hasMore());
    }
}
