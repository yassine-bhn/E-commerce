<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Inventory\InsufficientStockException;
use PHPUnit\Framework\TestCase;

/**
 * ERR-002 — an invariant violation is a domain exception, so one listener can own the HTTP
 * mapping instead of every controller re-deriving the status.
 */
final class InsufficientStockExceptionTest extends TestCase
{
    public function testInsufficientStockIsADomainException(): void
    {
        $exception = new InsufficientStockException('baignoire-d-code');

        self::assertInstanceOf(\DomainException::class, $exception);
        self::assertSame('baignoire-d-code', $exception->productSlug);
    }
}
