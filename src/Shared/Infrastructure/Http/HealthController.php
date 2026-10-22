<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Service\ReadinessService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * INF-002 / deployment probes: liveness answers without I/O, readiness reflects the critical
 * dependencies (PostgreSQL + migrations) and degrades on Meilisearch.
 */
final class HealthController
{
    #[Route('/healthz', name: 'app_health_liveness', methods: ['GET'])]
    public function liveness(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    #[Route('/readyz', name: 'app_health_readiness', methods: ['GET'])]
    public function readiness(ReadinessService $readiness): JsonResponse
    {
        $report = $readiness->report();

        return $this->json($report->toArray(), $report->httpStatus());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
