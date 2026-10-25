<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Domain\Http\Origin;
use App\Shared\Domain\Http\OriginAllowList;
use PHPUnit\Framework\TestCase;

/**
 * The two judgements STOREFRONT_CORS_ORIGIN serves, pinned on the same object: the guard's
 * canonical match (every spelling of an allow-listed origin is allowed) and the CORS answer's
 * exact-string match (only the configured spelling is echoed back to the browser). Neither set
 * may drift into the other's acceptance.
 */
final class OriginAllowListTest extends TestCase
{
    public function testAListedOriginMatchesCanonically(): void
    {
        $allowList = OriginAllowList::fromCommaSeparated('http://localhost:3000,https://shop.example.com');

        $candidate = Origin::fromUrl('https://shop.example.com/some/page?x=1');

        self::assertNotNull($candidate);
        self::assertTrue($allowList->contains($candidate));
    }

    /**
     * STOREFRONT_CORS_ORIGIN is a comma-separated env value whose entries are written with or
     * without surrounding whitespace: the split and trim are part of the interpretation, so a
     * spaced entry is the same entry.
     */
    public function testCommaAndWhitespaceEntriesAreSeparatedAndTrimmed(): void
    {
        $allowList = OriginAllowList::fromCommaSeparated('  http://localhost:3000 ,  https://shop.example.com  ');

        $storefront = Origin::fromUrl('http://localhost:3000');
        $admin = Origin::fromUrl('https://shop.example.com');

        self::assertNotNull($storefront);
        self::assertNotNull($admin);
        self::assertTrue($allowList->contains($storefront));
        self::assertTrue($allowList->contains($admin));
        self::assertTrue($allowList->containsRaw('http://localhost:3000'));
        self::assertTrue($allowList->containsRaw('https://shop.example.com'));
    }

    public function testAnUnlistedOriginDoesNotMatch(): void
    {
        $allowList = OriginAllowList::fromCommaSeparated('http://localhost:3000');

        $foreign = Origin::fromUrl('http://evil.example');

        self::assertNotNull($foreign);
        self::assertFalse($allowList->contains($foreign));
    }

    /**
     * The guard compares origins, not strings: an allow-list entry spelled with its default port,
     * or in a different case, is the same origin as the candidate's spelling.
     */
    public function testDefaultPortAndCaseSpellingsOfAnEntryMatchCanonically(): void
    {
        $allowList = OriginAllowList::fromCommaSeparated('HTTPS://shop.example.com:443');

        $candidate = Origin::fromUrl('https://shop.example.com');

        self::assertNotNull($candidate);
        self::assertTrue($allowList->contains($candidate));
    }

    public function testAnEntryThatNamesNoOriginMatchesNothing(): void
    {
        $allowList = OriginAllowList::fromCommaSeparated('not-a-url');

        $candidate = Origin::fromUrl('https://shop.example.com');

        self::assertNotNull($candidate);
        self::assertFalse($allowList->contains($candidate));
    }

    public function testAnEmptySegmentNamesNoOrigin(): void
    {
        $allowList = OriginAllowList::fromCommaSeparated('http://localhost:3000,,   ,https://shop.example.com');

        $storefront = Origin::fromUrl('http://localhost:3000');
        $admin = Origin::fromUrl('https://shop.example.com');

        self::assertNotNull($storefront);
        self::assertNotNull($admin);
        self::assertTrue($allowList->contains($storefront));
        self::assertTrue($allowList->contains($admin));
        self::assertFalse($allowList->containsRaw(''));
    }

    public function testAnEmptyAllowListAllowsNothing(): void
    {
        $allowList = OriginAllowList::fromCommaSeparated('');

        $candidate = Origin::fromUrl('http://localhost:3000');

        self::assertNotNull($candidate);
        self::assertFalse($allowList->contains($candidate));
        self::assertFalse($allowList->containsRaw('http://localhost:3000'));
    }

    /**
     * The CORS answer echoes the browser's own Origin header value back verbatim, so only the
     * configured spelling is allowed: a port the entry does not carry, another case, or a trailing
     * slash are different strings and are not echoed.
     */
    public function testTheRawJudgementAcceptsOnlyTheConfiguredSpelling(): void
    {
        $allowList = OriginAllowList::fromCommaSeparated('http://localhost:3000');

        self::assertTrue($allowList->containsRaw('http://localhost:3000'));
        self::assertFalse($allowList->containsRaw('http://localhost:3000/'));
        self::assertFalse($allowList->containsRaw('HTTP://LOCALHOST:3000'));
        self::assertFalse($allowList->containsRaw(' http://localhost:3000'));
    }
}
