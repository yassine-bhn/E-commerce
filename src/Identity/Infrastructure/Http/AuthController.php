<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Infrastructure\Security\CustomerUser;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/auth')]
final class AuthController
{
    #[Route('/login', name: 'identity_login', methods: ['POST'])]
    public function login(): never
    {
        throw new LogicException('The login route is handled by the security authenticator.');
    }

    #[Route('/logout', name: 'identity_logout', methods: ['POST', 'GET'])]
    public function logout(): never
    {
        throw new LogicException('The logout route is handled by the security firewall.');
    }

    #[Route('/me', name: 'identity_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?CustomerUser $user): JsonResponse
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'status' => $user->getStatus(),
        ]);
    }
}
