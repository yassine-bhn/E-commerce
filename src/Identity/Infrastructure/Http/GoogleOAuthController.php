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

            $this->security->login($customer, LoginAuthenticator::class);
        } catch (GoogleOAuthException) {
            return new RedirectResponse($this->storefrontAppUrl.'/connexion?oauth=error');
        }

        return new RedirectResponse($this->storefrontAppUrl.'/compte');
    }
}
