<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Domain\Http\Origin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parsing rules the cross-site guard judges origins by: every branch of Origin::fromUrl() is
 * pinned with concrete header values, so a widening of the acceptance set (a scheme the guard
 * never accepted, a spelling that stopped being canonical) cannot pass silently.
 */
final class OriginTest extends TestCase
{
    public function testSchemeAndHostAreLowercased(): void
    {
        $origin = self::origin('HTTPS://SHOP.Example.COM:8443/some/page?x=1');

        self::assertSame('https://shop.example.com:8443', $origin->toString());
    }

    /**
     * A default port is implied by the scheme: the browser that spells it out and the one that
     * omits it are the same origin to the guard, and the canonical spelling carries no port.
     */
    #[DataProvider('defaultPortSpellings')]
    public function testADefaultPortIsElided(string $url, string $canonical): void
    {
        $origin = self::origin($url);

        self::assertSame($canonical, $origin->toString());
        self::assertTrue($origin->equals(self::origin($canonical)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function defaultPortSpellings(): iterable
    {
        yield 'http 80' => ['http://shop.example.com:80/login', 'http://shop.example.com'];
        yield 'https 443' => ['https://shop.example.com:443/login', 'https://shop.example.com'];
    }

    public function testANonDefaultPortIsKept(): void
    {
        self::assertSame('http://localhost:3000', self::origin('http://localhost:3000/cart')->toString());

        // A port is only elided for the scheme that implies it: 80 on https is a real port.
        self::assertSame('https://localhost:80', self::origin('https://localhost:80/cart')->toString());
    }

    /**
     * Only http(s) names a web origin: the guard judges browser traffic, and any other scheme is
     * a value that can never match an allow-list of storefront origins.
     */
    #[DataProvider('nonHttpSchemes')]
    public function testANonHttpSchemeIsNotAnOrigin(string $url): void
    {
        self::assertNull(Origin::fromUrl($url), sprintf('"%s" must not parse to an origin.', $url));
    }

    /** @return iterable<string, array{string}> */
    public static function nonHttpSchemes(): iterable
    {
        yield 'ftp' => ['ftp://files.example.com/pub'];
        yield 'gopher' => ['gopher://files.example.com/0/menu'];
    }

    /**
     * A value that names no origin can never be trusted, whatever header carried it: the opaque
     * "null" Origin, a bare path Referer and an unparsable string are all refused by the guard.
     */
    #[DataProvider('unparseableValues')]
    public function testAValueThatNamesNoOriginParsesToNull(string $value): void
    {
        self::assertNull(Origin::fromUrl($value), sprintf('"%s" names no origin.', $value));
    }

    /** @return iterable<string, array{string}> */
    public static function unparseableValues(): iterable
    {
        yield 'opaque null origin' => ['null'];
        yield 'not a URL' => ['not-a-url'];
        yield 'relative path' => ['/relative/page'];
        yield 'scheme without host' => ['http://'];
        yield 'empty' => [''];
    }

    public function testEveryUrlOfTheSameOriginIsEqual(): void
    {
        $fromFullUrl = self::origin('https://shop.example.com:443/some/deep/page?keep=1#frag');
        $fromBareOrigin = self::origin('https://shop.example.com');

        self::assertTrue($fromFullUrl->equals($fromBareOrigin));
        self::assertSame($fromFullUrl->toString(), $fromBareOrigin->toString());
    }

    public function testOriginsDifferingInSchemeHostOrPortAreNotEqual(): void
    {
        $origin = self::origin('https://shop.example.com');

        self::assertFalse($origin->equals(self::origin('http://shop.example.com')), 'Scheme is part of the origin.');
        self::assertFalse($origin->equals(self::origin('https://api.example.com')), 'Host is part of the origin.');
        self::assertFalse($origin->equals(self::origin('https://shop.example.com:8443')), 'Port is part of the origin.');
    }

    /**
     * Parses or fails the test: a fixture that stops naming an origin is a fixture problem, not
     * the behaviour under test.
     */
    private static function origin(string $url): Origin
    {
        $origin = Origin::fromUrl($url);
        self::assertNotNull($origin, sprintf('"%s" must parse to an origin for this fixture.', $url));

        return $origin;
    }
}
