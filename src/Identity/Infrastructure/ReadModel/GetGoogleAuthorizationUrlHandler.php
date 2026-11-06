<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Query\GetGoogleAuthorizationUrl;
use App\Identity\Infrastructure\Security\GoogleOAuthClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.2 — the OAuth entry URL behind a query, so the controller never names the client.
 * Null means Google sign-in is not configured (controller answers 503).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetGoogleAuthorizationUrlHandler
{
    public function __construct(private GoogleOAuthClient $client)
    {
    }

    public function __invoke(GetGoogleAuthorizationUrl $query): ?string
    {
        if (!$this->client->isConfigured()) {
            return null;
        }

        return $this->client->authorizationUrl($query->state);
    }
}
