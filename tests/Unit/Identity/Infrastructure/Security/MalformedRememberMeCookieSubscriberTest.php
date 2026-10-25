<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\MalformedRememberMeCookieSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * R8: a client-forged remember-me cookie must never reach the firewall in a shape the vendor
 * decoder cannot handle. The guard drops exactly the payloads RememberMeDetails cannot decode.
 */
final class MalformedRememberMeCookieSubscriberTest extends TestCase
{
    private const COOKIE_NAME = 'REMEMBERME';

    /**
     * @param array<string, string|array<string>> $initialCookies
     * @param array<string, string|array<string>> $expectedCookies
     */
    #[DataProvider('rememberMeCookieProvider')]
    public function testDropsOnlyTheRememberMeCookieTheVendorDecoderCannotHandle(
        array $initialCookies,
        int $requestType,
        array $expectedCookies,
    ): void {
        $request = new Request(cookies: $initialCookies);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $requestType);

        (new MalformedRememberMeCookieSubscriber(self::COOKIE_NAME))->onKernelRequest($event);

        self::assertSame($expectedCookies, $request->cookies->all());
    }

    /**
     * @return iterable<string, array{array<string, string|array<string>>, int, array<string, string|array<string>>}>
     */
    public static function rememberMeCookieProvider(): iterable
    {
        yield 'decodable cookie is kept' => [
            [self::COOKIE_NAME => ':dXNlcg~~:2000000000:hash'],
            HttpKernelInterface::MAIN_REQUEST,
            [self::COOKIE_NAME => ':dXNlcg~~:2000000000:hash'],
        ];

        yield 'non integer expiry is removed' => [
            [self::COOKIE_NAME => ':dXNlcg~~:not-a-number:hash'],
            HttpKernelInterface::MAIN_REQUEST,
            [],
        ];

        yield 'truncated cookie is removed' => [
            [self::COOKIE_NAME => ':dXNlcg~~:2000000000'],
            HttpKernelInterface::MAIN_REQUEST,
            [],
        ];

        yield 'empty cookie value is removed' => [
            [self::COOKIE_NAME => ''],
            HttpKernelInterface::MAIN_REQUEST,
            [],
        ];

        yield 'identifier outside the base64 alphabet is removed' => [
            [self::COOKIE_NAME => ':***:2000000000:hash'],
            HttpKernelInterface::MAIN_REQUEST,
            [],
        ];

        yield 'base64 encoded malformed payload is removed' => [
            [self::COOKIE_NAME => 'OnVzZXI6MjAwMDAwMDAwMA=='],
            HttpKernelInterface::MAIN_REQUEST,
            [],
        ];

        yield 'array valued cookie is kept without failing' => [
            [self::COOKIE_NAME => ['x']],
            HttpKernelInterface::MAIN_REQUEST,
            [self::COOKIE_NAME => ['x']],
        ];

        yield 'absent cookie is left absent' => [
            [],
            HttpKernelInterface::MAIN_REQUEST,
            [],
        ];

        yield 'cookie stored under a different name is left untouched' => [
            ['OTHER' => ':dXNlcg~~:not-an-int:x'],
            HttpKernelInterface::MAIN_REQUEST,
            ['OTHER' => ':dXNlcg~~:not-an-int:x'],
        ];

        yield 'sub request cookie is left untouched' => [
            [self::COOKIE_NAME => ':dXNlcg~~:not-a-number:hash'],
            HttpKernelInterface::SUB_REQUEST,
            [self::COOKIE_NAME => ':dXNlcg~~:not-a-number:hash'],
        ];
    }
}
