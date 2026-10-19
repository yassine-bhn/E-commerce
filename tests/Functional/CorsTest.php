<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CorsTest extends WebTestCase
{
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
}
