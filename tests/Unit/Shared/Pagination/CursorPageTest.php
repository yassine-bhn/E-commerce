<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Pagination;

use App\Shared\Application\Pagination\CursorPage;
use PHPUnit\Framework\TestCase;

final class CursorPageTest extends TestCase
{
    public function testToArrayExactShapeWithNextCursor(): void
    {
        $page = new CursorPage(['a', 'b'], 20, true, 'eyJrIjoiayIsImkiOiJpIn0');

        self::assertSame(
            [
                'items' => ['a', 'b'],
                'meta' => [
                    'limit' => 20,
                    'hasMore' => true,
                    'nextCursor' => 'eyJrIjoiayIsImkiOiJpIn0',
                ],
            ],
            $page->toArray(),
        );
    }

    public function testToArrayExactShapeWithoutNextCursor(): void
    {
        $page = new CursorPage([], 10, false, null);

        self::assertSame(
            [
                'items' => [],
                'meta' => [
                    'limit' => 10,
                    'hasMore' => false,
                    'nextCursor' => null,
                ],
            ],
            $page->toArray(),
        );
    }
}
