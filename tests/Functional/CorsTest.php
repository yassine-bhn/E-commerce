<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class CorsTest extends WebTestCase
{
    private const ALLOWED_ORIGIN = 'http://localhost:3000';

    /**
     * Well-formed but unsigned remember-me value: the firewall rejects it, so
     * every request below exercises the error path while still presenting the
     * credential that made browsers switch to credentialed CORS requests.
     */
    private const REMEMBER_ME_COOKIE_VALUE = ':dXNlckBleGFtcGxlLmNvbQ~~:9999999999:0123456789abcdef';

    public function testOptionsPreflightReturnsCorsHeadersForAllowedOrigin(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/api/v1/storefront/products', [], [], ['HTTP_ORIGIN' => 'http://localhost:3000']);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function testGetRequestFromAllowedOriginEchoesOrigin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/products', [], [], ['HTTP_ORIGIN' => 'http://localhost:3000']);

        self::assertSame('http://localhost:3000', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testGetRequestFromUnknownOriginHasNoAllowOriginHeader(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/products', [], [], ['HTTP_ORIGIN' => 'http://evil.example']);

        self::assertNull($client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testRememberMeRequestFromUnknownOriginGrantsNoCorsOrigin(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('REMEMBERME', self::REMEMBER_ME_COOKIE_VALUE, null, '/', 'localhost'));

        $client->request('GET', '/api/v1/auth/me', [], [], ['HTTP_ORIGIN' => 'http://evil.example']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $response = $client->getResponse();
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function testRememberMeRequestFromAllowedOriginGetsItsOwnOriginAndCredentials(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new Cookie('REMEMBERME', self::REMEMBER_ME_COOKIE_VALUE, null, '/', 'localhost'));

        $client->request('GET', '/api/v1/auth/me', [], [], ['HTTP_ORIGIN' => self::ALLOWED_ORIGIN]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $response = $client->getResponse();
        self::assertSame(self::ALLOWED_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }
}
