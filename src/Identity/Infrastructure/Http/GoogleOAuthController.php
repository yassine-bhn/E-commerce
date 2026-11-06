<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\LinkGoogleAccount;
use App\Identity\Application\Query\GetGoogleAuthorizationUrl;
use App\Identity\Application\Query\GetGoogleUser;
use App\Identity\Application\Query\GetLoginUser;
use App\Identity\Domain\Model\GoogleOAuthException;
use App\Identity\Infrastructure\Security\LoginAuthenticator;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

#[Route('/api/v1/auth/google')]
final class GoogleOAuthController
{
    public function __construct(
        private MessengerQuery $queries,
        private MessageBusInterface $commandBus,
        private Security $security,
        #[Autowire('%env(STOREFRONT_APP_URL)%')]
        private string $storefrontAppUrl,
    ) {
    }

    #[Route('/redirect', name: 'identity_google_redirect', methods: ['GET'])]
    public function redirect(Request $request): Response
    {
        $state = bin2hex(random_bytes(32));
        $request->getSession()->set('google_oauth_state', $state);

        /** @var string|null $url */
        $url = $this->queries->dispatch(new GetGoogleAuthorizationUrl($state));
        if (null === $url) {
            return new JsonResponse(['error' => 'Google sign-in is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new RedirectResponse($url);
    }

    #[Route('/callback', name: 'identity_google_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
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
            $user = $this->queries->dispatch(new GetGoogleUser($code));
        } catch (HandlerFailedException $e) {
            // the exchange failure surfaces through the bus wrapped in HandlerFailedException
            if (!$e->getPrevious() instanceof GoogleOAuthException) {
                throw $e;
            }

            return new RedirectResponse($this->storefrontAppUrl.'/connexion?oauth=error');
        }

        if (null === $user) {
            return new JsonResponse(['error' => 'Google sign-in is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $this->commandBus->dispatch(new LinkGoogleAccount($user->googleId, $user->email, $user->name));

        $customer = $this->queries->dispatch(new GetLoginUser($user->email));

        // R13: a Google login is session-only. The badge must state that preference itself,
        // because CheckRememberMeConditionsListener falls back to the request's `_remember_me`
        // parameter when the badge has none — and a caller appending `?_remember_me=1` to this
        // GET callback would otherwise turn the OAuth login into a 14-day persistent one.
        // Pinning `_remember_me => false` keeps the badge disabled for any request input, and
        // RememberMeListener still clears a cookie an earlier password login left behind.
        $this->security->login($customer, LoginAuthenticator::class, null, [new RememberMeBadge(['_remember_me' => false])]);

        return new RedirectResponse($this->storefrontAppUrl.'/compte');
    }
}
