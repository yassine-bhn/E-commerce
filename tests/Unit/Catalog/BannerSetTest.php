<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Domain\Model\BannerImage;
use App\Catalog\Domain\Model\BannerImageNotFoundException;
use App\Catalog\Domain\Model\BannerRejectedException;
use App\Catalog\Domain\Model\BannerSet;
use PHPUnit\Framework\TestCase;

/**
 * R18/R20 — the site banner is one ordered image set whose order is exactly what the admin sent.
 */
final class BannerSetTest extends TestCase
{
    public function testASetBuiltFromThreePathsKeepsTheSentOrderWithDenseSortOrders(): void
    {
        $set = BannerSet::fromPaths([
            '/uploads/products/018eb9c0-0000-7000-8000-000000000001.png',
            '/uploads/products/018eb9c0-0000-7000-8000-000000000002.jpg',
            '/uploads/products/018eb9c0-0000-7000-8000-000000000003.webp',
        ]);

        self::assertSame(3, $set->count());
        self::assertSame([
            '/uploads/products/018eb9c0-0000-7000-8000-000000000001.png',
            '/uploads/products/018eb9c0-0000-7000-8000-000000000002.jpg',
            '/uploads/products/018eb9c0-0000-7000-8000-000000000003.webp',
        ], $set->paths());
        self::assertSame([0, 1, 2], array_map(static fn ($image): int => $image->sortOrder, $set->images()));
    }

    public function testSelectingNoImageIsRefusedBecauseTheCardinalityStartsAtOne(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('The banner must contain between 1 and 10 images, 0 selected.');

        BannerSet::fromPaths([]);
    }

    public function testSelectingElevenImagesIsRefusedByTheUpperBound(): void
    {
        $paths = [];
        for ($index = 1; $index <= 11; ++$index) {
            $paths[] = sprintf('/uploads/products/banner-%02d.png', $index);
        }

        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('The banner must contain between 1 and 10 images, 11 selected.');

        BannerSet::fromPaths($paths);
    }

    public function testAnEmptyImageReferenceIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('A banner image reference cannot be empty.');

        BannerSet::fromPaths(['']);
    }

    public function testAnExternalUrlIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('A banner image must reference a dashboard upload, not an external URL: "https://cdn.example.com/hero.png".');

        BannerSet::fromPaths(['https://cdn.example.com/hero.png']);
    }

    public function testAPathOutsideTheUploadsAreaIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('A banner image must live under the dashboard uploads area "/uploads/": "/etc/passwd".');

        BannerSet::fromPaths(['/etc/passwd']);
    }

    public function testAPathThatEscapesTheUploadsAreaIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('A banner image must live under the dashboard uploads area "/uploads/": "/uploads/products/../../.env".');

        BannerSet::fromPaths(['/uploads/products/../../.env']);
    }

    public function testAReferenceThatIsNotAnUploadedImageFormatIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('A banner image must be one of the JPEG, PNG or WebP formats the dashboard upload produces: "/uploads/products/shell.php".');

        BannerSet::fromPaths(['/uploads/products/shell.php']);
    }

    public function testTheWholeOrderedSetIsRebuiltFromThePathsTheAdminSent(): void
    {
        $paths = [
            '/uploads/products/a.png',
            '/uploads/products/b.jpg',
            '/uploads/products/c.webp',
        ];

        self::assertSame($paths, BannerSet::fromPaths($paths)->paths());
    }

    public function testTheSameImageCannotAppearTwiceInOneSet(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('The same image cannot appear twice in one banner set: "/uploads/products/duplicate.png".');

        BannerSet::fromPaths([
            '/uploads/products/duplicate.png',
            '/uploads/products/other.png',
            '/uploads/products/duplicate.png',
        ]);
    }

    public function testRemovingOneSlideKeepsTheRemainingOnesInTheirOrder(): void
    {
        $set = BannerSet::reconstitute([
            new BannerImage('11111111-1111-1111-1111-111111111111', '/uploads/products/first.png', 0),
            new BannerImage('22222222-2222-2222-2222-222222222222', '/uploads/products/second.png', 1),
            new BannerImage('33333333-3333-3333-3333-333333333333', '/uploads/products/third.png', 2),
        ]);

        $set->remove('22222222-2222-2222-2222-222222222222');

        self::assertSame(2, $set->count());
        self::assertSame(['/uploads/products/first.png', '/uploads/products/third.png'], $set->paths());
    }

    public function testRemovingTheLastRemainingSlideIsAllowedAndLeavesAnEmptySet(): void
    {
        $set = BannerSet::reconstitute([
            new BannerImage('44444444-4444-4444-4444-444444444444', '/uploads/products/only.png', 0),
        ]);

        $set->remove('44444444-4444-4444-4444-444444444444');

        self::assertSame(0, $set->count());
        self::assertSame([], $set->paths());
    }

    public function testRemovingAnUnknownSlideIsReportedAsNotFound(): void
    {
        $set = BannerSet::reconstitute([
            new BannerImage('55555555-5555-5555-5555-555555555555', '/uploads/products/keep.png', 0),
        ]);

        $this->expectException(BannerImageNotFoundException::class);
        $this->expectExceptionMessage('Banner image "66666666-6666-6666-6666-666666666666" does not exist.');

        $set->remove('66666666-6666-6666-6666-666666666666');
    }

    public function testReorderingAssignsTheNewDenseSequence(): void
    {
        $set = BannerSet::reconstitute([
            new BannerImage('aaaaaaaa-0000-0000-0000-000000000001', '/uploads/products/a.png', 0),
            new BannerImage('aaaaaaaa-0000-0000-0000-000000000002', '/uploads/products/b.png', 1),
            new BannerImage('aaaaaaaa-0000-0000-0000-000000000003', '/uploads/products/c.png', 2),
        ]);

        $set->reorder([
            'aaaaaaaa-0000-0000-0000-000000000003',
            'aaaaaaaa-0000-0000-0000-000000000001',
            'aaaaaaaa-0000-0000-0000-000000000002',
        ]);

        self::assertSame(
            ['/uploads/products/c.png', '/uploads/products/a.png', '/uploads/products/b.png'],
            $set->paths(),
        );
        self::assertSame([0, 1, 2], array_map(static fn (BannerImage $i): int => $i->sortOrder, $set->images()));
    }

    public function testAReorderThatDoesNotListEverySlideExactlyOnceIsRefused(): void
    {
        $set = BannerSet::reconstitute([
            new BannerImage('bbbbbbbb-0000-0000-0000-000000000001', '/uploads/products/x.png', 0),
            new BannerImage('bbbbbbbb-0000-0000-0000-000000000002', '/uploads/products/y.png', 1),
        ]);

        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('A banner reorder must list every image id exactly once: the banner holds 2 images, 1 ids were given.');

        $set->reorder(['bbbbbbbb-0000-0000-0000-000000000001']);
    }
}
