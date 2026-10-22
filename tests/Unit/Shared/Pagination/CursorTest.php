<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Pagination;

use App\Shared\Application\Pagination\Cursor;
use App\Shared\Application\Pagination\InvalidCursorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        $encoded = Cursor::encode('2026-10-20T11:00:00Z', '019a-...');

        self::assertSame(
            ['key' => '2026-10-20T11:00:00Z', 'id' => '019a-...'],
            Cursor::decode($encoded),
        );
    }

    public function testEncodeDecodeRoundTripWithSpecialCharacters(): void
    {
        $key = 'k+é/ =value';
        $id = 'id+b/c=x☃';

        $encoded = Cursor::encode($key, $id);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
        self::assertSame(['key' => $key, 'id' => $id], Cursor::decode($encoded));
    }

    #[DataProvider('invalidCursorProvider')]
    public function testDecodeThrowsForInvalidInput(string $raw): void
    {
        $this->expectException(InvalidCursorException::class);

        Cursor::decode($raw);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCursorProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64' => ['not-base64!!'];
        yield 'json object without keys' => [base64_encode('{}')];
        yield 'non string key' => [base64_encode('{"k":1,"i":"x"}')];
        yield 'non string id' => [base64_encode('{"k":"x","i":2}')];
        yield 'json string not array' => [base64_encode('"hello"')];
        yield 'json number not array' => [base64_encode('123')];
        yield 'invalid json' => [base64_encode('{"k":')];
    }

    public function testCreateMessageIsStable(): void
    {
        self::assertSame('Invalid cursor', InvalidCursorException::create()->getMessage());
    }
}
