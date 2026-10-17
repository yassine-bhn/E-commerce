<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RateLimitingTest extends WebTestCase
{
    public function testContactSubmissionRateLimitingReturns429AfterLimitExceeded(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        if ($container->has('cache.rate_limiter')) {
            /** @var \Psr\Cache\CacheItemPoolInterface $pool */
            $pool = $container->get('cache.rate_limiter');
            $pool->clear();
        }

        // Contact submission limit is 5 requests per minute
        $payload = json_encode([
            'name' => 'Rate Test',
            'email' => 'ratetest@example.com',
            'message' => 'Testing rate limiting protection',
        ], JSON_THROW_ON_ERROR);

        // First 5 requests should pass
        for ($i = 0; $i < 5; ++$i) {
            $client->request(
                'POST',
                '/api/v1/storefront/contact',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.42'],
                $payload,
            );
            self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode(), "Request $i should succeed");
        }

        // 6th request from same IP must hit rate limit (HTTP 429)
        $client->request(
            'POST',
            '/api/v1/storefront/contact',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.42'],
            $payload,
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
        self::assertTrue($response->headers->has('X-RateLimit-Remaining'));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Too Many Requests', $data['error']);
        self::assertArrayHasKey('retryAfter', $data);
    }
}
