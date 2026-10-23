<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\LinkGoogleAccount;
use App\Identity\Domain\Model\GoogleOAuthException;
use App\Identity\Infrastructure\Security\CustomerProfileUserProvider;
use App\Identity\Infrastructure\Security\GoogleOAuthClient;
use App\Identity\Infrastructure\Security\LoginAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

#[Route('/api/v1/auth/google')]
final class GoogleOAuthController
{
    public function __construct(
        private GoogleOAuthClient $client,
        private MessageBusInterface $commandBus,
        private CustomerProfileUserProvider $userProvider,
        private Security $security,
        #[Autowire('%env(STOREFRONT_APP_URL)%')]
        private string $storefrontAppUrl,
    ) {
    }

    #[Route('/redirect', name: 'identity_google_redirect', methods: ['GET'])]
    public function redirect(Request $request): Response
    {
        if (!$this->client->isConfigured()) {
            return new JsonResponse(['error' => 'Google sign-in is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $state = bin2hex(random_bytes(32));
        $request->getSession()->set('google_oauth_state', $state);

        return new RedirectResponse($this->client->authorizationUrl($state));
    }

    #[Route('/callback', name: 'identity_google_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        if (!$this->client->isConfigured()) {
            return new JsonResponse(['error' => 'Google sign-in is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $session = $request->getSession();
        $providedState = $request->query->get('state');
        $expectedState = $session->get('google_oauth_state');
        // The state is single-use: consume it before any further processing.
        $session->remove('google_oauth_state');

        if (!is_string($providedState) || '' === $providedState
            || !is_string($expectedState) || '' === $expectedState
            || !hash_equals($expectedState, $providedState)) {
            return new JsonResponse(['error' => 'Invalid OAuth state.'], Response::HTTP_BAD_REQUEST);
        }

        $code = $request->query->get('code');

        if (!is_string($code) || '' === $code) {
            return new RedirectResponse($this->storefrontAppUrl.'/connexion?oauth=error');
        }

        try {
            $user = $this->client->fetchUser($code);
            $this->commandBus->dispatch(new LinkGoogleAccount($user['googleId'], $user['email'], $user['name']));

            $customer = $this->userProvider->loadUserByIdentifier($user['email']);

            // R13: a Google login is session-only. The badge must state that preference itself,
            // because CheckRememberMeConditionsListener falls back to the request's `_remember_me`
            // parameter when the badge has none — and a caller appending `?_remember_me=1` to this
            // GET callback would otherwise turn the OAuth login into a 14-day persistent one.
            // Pinning `_remember_me => false` keeps the badge disabled for any request input, and
            // RememberMeListener still clears a cookie an earlier password login left behind.
            $this->security->login($customer, LoginAuthenticator::class, null, [new RememberMeBadge(['_remember_me' => false])]);
        } catch (GoogleOAuthException) {
            return new RedirectResponse($this->storefrontAppUrl.'/connexion?oauth=error');
        }

        return new RedirectResponse($this->storefrontAppUrl.'/compte');
    }
}
