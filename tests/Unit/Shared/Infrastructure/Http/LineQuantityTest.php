<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\LineQuantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineQuantityTest extends TestCase
{
    public function testAcceptsAPositiveInteger(): void
    {
        self::assertSame(3, LineQuantity::of(['slug' => 'baignoire-d-code', 'quantity' => 3]));
    }

    public function testAcceptsADigitString(): void
    {
        self::assertSame(2, LineQuantity::of(['quantity' => '2']));
    }

    public function testFallsBackToTheLegacyQtyKey(): void
    {
        self::assertSame(4, LineQuantity::of(['qty' => 4]));
    }

    /** @param array<string, mixed> $line */
    #[DataProvider('rejectedLineProvider')]
    public function testReturnsNullForAnythingThatIsNotAPositiveInteger(array $line): void
    {
        self::assertNull(LineQuantity::of($line));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rejectedLineProvider(): iterable
    {
        yield 'zero' => [['quantity' => 0]];
        yield 'negative' => [['quantity' => -2]];
        yield 'zero string' => [['quantity' => '0']];
        yield 'negative string' => [['quantity' => '-2']];
        yield 'non numeric string' => [['quantity' => 'two']];
        yield 'padded digit string' => [['quantity' => ' 2']];
        yield 'empty string' => [['quantity' => '']];
        yield 'float' => [['quantity' => 1.5]];
        yield 'bool' => [['quantity' => true]];
        yield 'array' => [['quantity' => [1]]];
        yield 'explicit null' => [['quantity' => null]];
        yield 'missing key' => [[]];
    }
}
