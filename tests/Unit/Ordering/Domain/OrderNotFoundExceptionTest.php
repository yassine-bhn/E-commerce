<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Model\OrderNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * ERR-002 — a missing order is a domain outcome, so the exception belongs to the same
 * `DomainException` family as the state-transition violations beside it.
 */
final class OrderNotFoundExceptionTest extends TestCase
{
    public function testOrderNotFoundIsADomainException(): void
    {
        $exception = new OrderNotFoundException('01929312-7000-7000-8000-000000000093');

        self::assertInstanceOf(\DomainException::class, $exception);
        self::assertStringContainsString('01929312-7000-7000-8000-000000000093', $exception->getMessage());
    }
}
