<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\Http\RateLimitChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class RateLimitCheckerTest extends TestCase
{
    public function testCheckReturnsNullWhenLimitNotExceeded(): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter = $this->createMock(LimiterInterface::class);
        $rateLimit = new RateLimit(9, new \DateTimeImmutable('+1 minute'), true, 10);

        $limiter->expects(self::once())
            ->method('consume')
            ->with(1)
            ->willReturn($rateLimit);

        $factory->expects(self::once())
            ->method('create')
            ->with('192.168.1.100')
            ->willReturn($limiter);

        $checker = new RateLimitChecker();
        $request = Request::create('/api/v1/storefront/orders', 'POST', [], [], [], ['REMOTE_ADDR' => '192.168.1.100']);

        $response = $checker->check($factory, $request);

        self::assertNull($response);
    }

    public function testCheckReturns429JsonResponseWhenLimitExceeded(): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $limiter = $this->createMock(LimiterInterface::class);
        $retryAt = new \DateTimeImmutable('+25 seconds');
        $rateLimit = new RateLimit(0, $retryAt, false, 10);

        $limiter->expects(self::once())
            ->method('consume')
            ->with(1)
            ->willReturn($rateLimit);

        $factory->expects(self::once())
            ->method('create')
            ->with('cust-123')
            ->willReturn($limiter);

        $checker = new RateLimitChecker();
        $request = Request::create('/api/v1/storefront/orders', 'POST');
        $request->headers->set('X-Customer-Id', 'cust-123');

        $response = $checker->check($factory, $request, 'cust-123');

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
        self::assertSame('0', $response->headers->get('X-RateLimit-Remaining'));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Too Many Requests', $data['error']);
        self::assertArrayHasKey('retryAfter', $data);
    }
}
