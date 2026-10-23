<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class LoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && '/api/v1/auth/login' === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        $email = $payload['email'] ?? null;
        $password = $payload['password'] ?? null;

        if (!is_string($email) || '' === $email || !is_string($password) || '' === $password) {
            throw new BadRequestHttpException('Email and password are required.');
        }

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            [new RememberMeBadge(['_remember_me' => self::isRememberMeRequested($payload)])],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();

        if (!$user instanceof CustomerUser) {
            throw new \LogicException('Authenticated user must be a customer.');
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Reads the unticked/ticked state of the "remember me" checkbox from the JSON body.
     *
     * R2 makes the field a strict boolean: absent means unticked, and any other present
     * value ("yes", 1, []) is a client error instead of being coerced, so the caller never
     * gets a credential it did not literally ask for. The error uses the same
     * BadRequestHttpException as a malformed body, hence the same 400 JSON response.
     *
     * The badge is attached on every login — flagged or not — because Symfony's
     * RememberMeListener first cancels any cookie left by an earlier login and
     * only then honours the badge: an unticked box therefore forgets the customer
     * instead of silently keeping an older credential alive.
     *
     * @param array<array-key, mixed> $payload
     */
    private static function isRememberMeRequested(array $payload): bool
    {
        if (!\array_key_exists('remember_me', $payload)) {
            return false;
        }

        $rememberMe = $payload['remember_me'];

        if (!\is_bool($rememberMe)) {
            throw new BadRequestHttpException('"remember_me" must be a boolean.');
        }

        return $rememberMe;
    }
}
