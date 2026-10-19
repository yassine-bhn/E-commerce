<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\RegisterCustomer;
use App\Identity\Application\Command\RequestPasswordReset;
use App\Identity\Application\Command\ResetPassword;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use App\Identity\Domain\Model\InvalidPasswordResetTokenException;
use App\Identity\Infrastructure\Security\CustomerUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/register', name: 'identity_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
        $phone = is_string($payload['phone'] ?? null) ? trim($payload['phone']) : '';
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : 'particulier';
        $company = is_string($payload['company'] ?? null) ? $payload['company'] : null;
        $mf = is_string($payload['mf'] ?? null) ? $payload['mf'] : null;

        if ('' === $name) {
            return new JsonResponse(['error' => 'The name is required.'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'A valid email address is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($password) < 8) {
            return new JsonResponse(['error' => 'The password must be at least 8 characters long.'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $phone) {
            return new JsonResponse(['error' => 'The phone number is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (!in_array($type, ['particulier', 'pro'], true)) {
            return new JsonResponse(['error' => 'The account type must be either "particulier" or "pro".'], Response::HTTP_BAD_REQUEST);
        }

        $id = Uuid::v7()->toRfc4122();

        try {
            $this->commandBus->dispatch(new RegisterCustomer(
                id: $id,
                name: $name,
                email: $email,
                password: $password,
                phone: $phone,
                accountType: $type,
                company: $company,
                mf: $mf,
            ));
        } catch (HandlerFailedException $exception) {
            $emailAlreadyUsed = $exception->getWrappedExceptions(EmailAlreadyUsedException::class, true);

            if ([] === $emailAlreadyUsed) {
                throw $exception;
            }

            return new JsonResponse(['error' => reset($emailAlreadyUsed)->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'id' => $id,
            'email' => $email,
            'roles' => ['ROLE_USER'],
            'status' => 'active',
        ], Response::HTTP_CREATED);
    }

    #[Route('/forgot-password', name: 'identity_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';

        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'A valid email address is required.'], Response::HTTP_BAD_REQUEST);
        }

        // Neutral response: never disclose whether an account exists for this email.
        $this->commandBus->dispatch(new RequestPasswordReset(email: $email));

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/reset-password', name: 'identity_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $token = is_string($payload['token'] ?? null) ? trim($payload['token']) : '';
        $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';

        if ('' === $token) {
            return new JsonResponse(['error' => 'The token is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($password) < 8) {
            return new JsonResponse(['error' => 'The password must be at least 8 characters long.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new ResetPassword(token: $token, newPassword: $password));
        } catch (HandlerFailedException $exception) {
            $invalidToken = $exception->getWrappedExceptions(InvalidPasswordResetTokenException::class, true);

            if ([] === $invalidToken) {
                throw $exception;
            }

            return new JsonResponse(['error' => reset($invalidToken)->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['status' => 'reset']);
    }

    #[Route('/login', name: 'identity_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('The login route is handled by the security authenticator.');
    }

    #[Route('/logout', name: 'identity_logout', methods: ['POST', 'GET'])]
    public function logout(): never
    {
        throw new \LogicException('The logout route is handled by the security firewall.');
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
