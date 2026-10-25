<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

/**
 * Drops a remember-me cookie the vendor decoder would crash on, before the firewall reads it.
 *
 * RememberMeDetails::fromRawCookie() reports most malformed payloads as an AuthenticationException,
 * which the firewall turns into an ordinary 401 (R8). One shape escapes that path: a payload with
 * four segments whose expiry is not an integer. That segment is handed to RememberMeDetails'
 * `int $expires` constructor argument, so a client-forged value raises a TypeError and the response
 * becomes a 500.
 *
 * The vendor decoder stays the single source of truth: this guard only asks it whether the payload
 * can be built, and removes the cookie when it cannot. A payload the decoder accepts is left for the
 * firewall, which still verifies the signature and the expiry. Removing an AuthenticationException
 * payload reaches the same authentication outcome as leaving it for the firewall — the request is
 * anonymous, with a JSON 401 on protected routes — but the guard skips the firewall's failure path:
 * no clearing Set-Cookie is sent for the rejected cookie and no "Remember me authentication failed."
 * line is logged. A TypeError, which would otherwise escape as a 500, becomes that same
 * unauthenticated request.
 *
 * This class is a request-input workaround, not part of the credential design: delete it once the
 * vendor validates the expiry segment before constructing RememberMeDetails; see the removal
 * condition in docs/adr/ADR-0011-remember-me-credential.md.
 */
final class MalformedRememberMeCookieSubscriber implements EventSubscriberInterface
{
    /** Must run before the firewall's kernel.request listener, registered with priority 8. */
    private const PRIORITY = 16;

    /**
     * The name is the same parameter the `main` firewall's remember_me block reads, so the guard
     * cannot drift from the cookie the firewall reads (see config/services.yaml).
     */
    public function __construct(private readonly string $cookieName)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only main requests are authenticated by the firewall.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // InputBag::get() throws a BadRequestException on an array-valued cookie, so read the
        // decoded bag directly; a non-string value never reaches the decoder anyway
        // (RememberMeAuthenticator::supports() requires a scalar).
        $rawCookie = $request->cookies->all()[$this->cookieName] ?? null;

        if (!\is_string($rawCookie)) {
            return;
        }

        try {
            RememberMeDetails::fromRawCookie($rawCookie);
        } catch (AuthenticationException|\TypeError) {
            $request->cookies->remove($this->cookieName);
        }
    }
}
