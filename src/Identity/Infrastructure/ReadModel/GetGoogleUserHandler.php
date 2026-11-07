<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

use App\Identity\Application\Dto\GoogleUser;
use App\Identity\Application\Query\GetGoogleUser;
use App\Identity\Infrastructure\Security\GoogleOAuthClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.2 — the code-for-identity exchange behind a query. Null means Google sign-in is
 * not configured (503); a failed exchange throws GoogleOAuthException (controller redirects to
 * the storefront error).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class GetGoogleUserHandler
{
    public function __construct(private GoogleOAuthClient $client)
    {
    }

    public function __invoke(GetGoogleUser $query): ?GoogleUser
    {
        if (!$this->client->isConfigured()) {
            return null;
        }

        $user = $this->client->fetchUser($query->code);

        return new GoogleUser(
            googleId: $user['googleId'],
            email: $user['email'],
            name: $user['name'],
        );
    }
}
