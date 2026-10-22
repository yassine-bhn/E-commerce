<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Port\HealthProbe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Meilisearch only powers catalog search (ECO-008), so its failure degrades readiness but never
 * takes the API out of rotation.
 */
final class SearchHealthProbe implements HealthProbe
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MEILISEARCH_URL)%')]
        private readonly string $meilisearchUrl,
    ) {
    }

    public function name(): string
    {
        return 'search';
    }

    public function isCritical(): bool
    {
        return false;
    }

    public function check(): bool
    {
        try {
            $status = $this->httpClient
                ->request('GET', rtrim($this->meilisearchUrl, '/').'/health', [
                    'timeout' => 1,
                    'max_duration' => 1,
                ])
                ->getStatusCode();
        } catch (TransportExceptionInterface) {
            return false;
        }

        return $status >= 200 && $status < 300;
    }
}
