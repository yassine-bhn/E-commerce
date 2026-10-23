<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Service;

use App\Shared\Application\Dto\StorefrontProductSnapshot;
use App\Shared\Application\Port\StorefrontCatalogPort;
use App\Shared\Application\Port\StorefrontPricingPort;
use App\Shared\Application\Service\StorefrontQuoteService;
use App\Shared\Domain\Inventory\InsufficientStockException;
use App\Shared\Domain\Storefront\DeliveryPolicy;
use PHPUnit\Framework\TestCase;

final class StorefrontQuoteServiceTest extends TestCase
{
    public function testQuoteRejectsNonPositiveQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new StorefrontQuoteService($this->catalog(), $this->pricing()))->quote(
            [['slug' => 'lavabo', 'quantity' => 0]],
            null,
            'home',
        );
    }

    public function testQuoteRejectsQuantityAboveAvailableStock(): void
    {
        $this->expectException(InsufficientStockException::class);

        (new StorefrontQuoteService($this->catalog(2), $this->pricing()))->quote(
            [['slug' => 'lavabo', 'quantity' => 3]],
            null,
            'home',
        );
    }

    private function catalog(?int $stockQuantity = null): StorefrontCatalogPort
    {
        return new class($stockQuantity) implements StorefrontCatalogPort {
            public function __construct(private readonly ?int $stockQuantity)
            {
            }

            public function getBySlug(string $slug): StorefrontProductSnapshot
            {
                return new StorefrontProductSnapshot('id', 'lavabo', 'Lavabo', 680, 'in', [], $this->stockQuantity);
            }
        };
    }

    private function pricing(): StorefrontPricingPort
    {
        return new class implements StorefrontPricingPort {
            public function deliveryPolicy(): DeliveryPolicy
            {
                return new DeliveryPolicy(15, 1000);
            }

            public function promoBasisPoints(?string $code): ?int
            {
                return null;
            }
        };
    }
}
