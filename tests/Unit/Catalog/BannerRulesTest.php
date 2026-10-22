<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Service\BannerRules;
use App\Catalog\Domain\Model\BannerRejectedException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * ERR-001 — the shape of the dashboard payload is settled before a command reaches the bus, so the
 * domain never sees structurally impossible input.
 */
final class BannerRulesTest extends TestCase
{
    public function testAWellFormedImageListBecomesTheOrderedPathList(): void
    {
        $rules = $this->rules();

        self::assertSame(
            ['/uploads/products/a.png', '/uploads/products/b.jpg'],
            $rules->imagePathsFrom(['images' => ['/uploads/products/a.png', ' /uploads/products/b.jpg ']]),
        );
    }

    public function testAMissingImagesFieldIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('The "images" field must be a JSON array of dashboard upload paths.');

        $this->rules()->imagePathsFrom(['path' => '/uploads/products/a.png']);
    }

    public function testAnImagesFieldThatIsNotAListIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('The "images" field must be a JSON array of dashboard upload paths.');

        $this->rules()->imagePathsFrom(['images' => '/uploads/products/a.png']);
    }

    public function testANonStringImageEntryIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('The "images" field must contain only image path strings.');

        $this->rules()->imagePathsFrom(['images' => ['/uploads/products/a.png', 42]]);
    }

    public function testAnEmptyBodyIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('The request body must be a JSON object.');

        $this->rules()->imagePathsFrom(null);
    }

    public function testAWellFormedImageIdListBecomesTheOrderedIdList(): void
    {
        $rules = $this->rules();

        self::assertSame(
            ['018eb9c0-0000-7000-8000-000000000001', '018eb9c0-0000-7000-8000-000000000002'],
            $rules->imageIdsFrom(['imageIds' => ['018eb9c0-0000-7000-8000-000000000001', '018eb9c0-0000-7000-8000-000000000002']]),
        );
    }

    public function testAMissingImageIdListIsRefused(): void
    {
        $this->expectException(BannerRejectedException::class);
        $this->expectExceptionMessage('The "imageIds" field must be a JSON array of banner image ids.');

        $this->rules()->imageIdsFrom(['order' => []]);
    }

    private function rules(): BannerRules
    {
        $connection = $this->createStub(Connection::class);

        return new BannerRules($connection);
    }
}
