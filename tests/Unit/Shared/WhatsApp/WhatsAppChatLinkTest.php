<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\WhatsApp;

use App\Shared\Domain\WhatsApp\WhatsAppChatLink;
use App\Shared\Domain\WhatsApp\WhatsAppNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppChatLinkTest extends TestCase
{
    private const string NUMBER = '21600000000';
    private const string DEFAULT_MESSAGE = 'Hello, I would like more information about your products.';
    private const string HOSTILE_MESSAGE = 'a&b=c?d#e+f%g';
    private const string QUOTED_MESSAGE = 'say "hi" it\'s /a\\b';
    private const string ACCENTED_MESSAGE = 'Informations sur vos produits à Djerba ?';
    private const string ARABIC_MESSAGE = 'مرحبا، أريد المزيد من المعلومات عن منتجاتكم';

    public function testBuildsTheChatUrlForTheConfiguredNumberAndDefaultMessage(): void
    {
        $url = WhatsAppChatLink::build(WhatsAppNumber::fromString(self::NUMBER), self::DEFAULT_MESSAGE);

        self::assertSame(
            'https://wa.me/21600000000?text=Hello%2C%20I%20would%20like%20more%20information%20about%20your%20products.',
            $url,
        );
    }

    public function testOmitsTheQueryStringWhenTheMessageIsEmpty(): void
    {
        $url = WhatsAppChatLink::build(WhatsAppNumber::fromString(self::NUMBER), '');

        self::assertSame('https://wa.me/21600000000', $url);
        self::assertStringNotContainsString('?', $url);
    }

    public function testPercentEncodesTheQueryReservedCharactersSoTheQueryDecodesToTheOriginalMessage(): void
    {
        $url = WhatsAppChatLink::build(WhatsAppNumber::fromString(self::NUMBER), self::HOSTILE_MESSAGE);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        self::assertSame('text=a%26b%3Dc%3Fd%23e%2Bf%25g', $query);
        self::assertSame('text='.self::HOSTILE_MESSAGE, rawurldecode($query));
        self::assertSame('wa.me', parse_url($url, PHP_URL_HOST));
    }

    /**
     * R10 — quotes, slashes and backslashes are encoded too, so nothing in the message can escape the
     * text= value or close the parameter it lives in.
     */
    public function testPercentEncodesQuotesAndPathSeparators(): void
    {
        $url = WhatsAppChatLink::build(WhatsAppNumber::fromString(self::NUMBER), self::QUOTED_MESSAGE);

        self::assertSame('text=say%20%22hi%22%20it%27s%20%2Fa%5Cb', parse_url($url, PHP_URL_QUERY));
        self::assertSame('text='.self::QUOTED_MESSAGE, rawurldecode((string) parse_url($url, PHP_URL_QUERY)));
    }

    #[DataProvider('nonAsciiMessageProvider')]
    public function testRoundTripsAccentedLatinAndArabicMessages(string $message): void
    {
        $url = WhatsAppChatLink::build(WhatsAppNumber::fromString(self::NUMBER), $message);

        self::assertSame('text='.$message, rawurldecode((string) parse_url($url, PHP_URL_QUERY)));
    }

    /** @return iterable<string, array{string}> */
    public static function nonAsciiMessageProvider(): iterable
    {
        yield 'accented latin' => [self::ACCENTED_MESSAGE];
        yield 'arabic' => [self::ARABIC_MESSAGE];
    }

    #[DataProvider('everyMessageProvider')]
    public function testAlwaysTargetsTheHttpsWaMeDestination(string $message): void
    {
        $url = WhatsAppChatLink::build(WhatsAppNumber::fromString(self::NUMBER), $message);

        self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
        self::assertSame('wa.me', parse_url($url, PHP_URL_HOST));
    }

    /** @return iterable<string, array{string}> */
    public static function everyMessageProvider(): iterable
    {
        yield 'empty message' => [''];
        yield 'default message' => [self::DEFAULT_MESSAGE];
        yield 'hostile characters' => [self::HOSTILE_MESSAGE];
        yield 'accented latin' => [self::ACCENTED_MESSAGE];
        yield 'arabic' => [self::ARABIC_MESSAGE];
    }
}
