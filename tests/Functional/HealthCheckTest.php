<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthCheckTest extends WebTestCase
{
    public function testLivenessAnswersOkToAGuestWithoutTouchingDependencies(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['status' => 'ok'], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testReadinessAnswersOkWhenDatabaseAndMigrationsAreUpToDate(): void
    {
        $client = static::createClient();
        $client->request('GET', '/readyz');

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
        self::assertSame('ok', $body['checks']['database']);
        self::assertSame('ok', $body['checks']['migrations']);
        self::assertContains($body['checks']['search'], ['ok', 'degraded']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testReadinessAndLivenessArePublic(): void
    {
        $client = static::createClient();

        $client->request('GET', '/healthz');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertNotSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        $client->request('GET', '/readyz');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertNotSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }
}
