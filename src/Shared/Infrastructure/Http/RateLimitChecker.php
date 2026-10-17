<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class RateLimitChecker
{
    public function check(
        RateLimiterFactoryInterface $limiterFactory,
        Request $request,
        ?string $identifier = null,
        int $tokens = 1,
    ): ?JsonResponse {
        $key = $identifier ?? $request->getClientIp() ?? 'anonymous';
        $limiter = $limiterFactory->create($key);
        $limit = $limiter->consume($tokens);

        if ($limit->isAccepted()) {
            return null;
        }

        $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        $response = new JsonResponse(
            [
                'error' => 'Too Many Requests',
                'message' => sprintf('Rate limit exceeded. Please wait %d second(s) before retrying.', $retryAfterSeconds),
                'retryAfter' => $retryAfterSeconds,
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
        );

        $response->headers->set('Retry-After', (string) $retryAfterSeconds);
        $response->headers->set('X-RateLimit-Remaining', (string) $limit->getRemainingTokens());
        $response->headers->set('X-RateLimit-Retry-After', (string) $retryAfterSeconds);

        return $response;
    }
}
