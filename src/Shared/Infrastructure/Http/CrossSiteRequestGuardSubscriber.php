<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Http\Origin;
use App\Shared\Domain\Http\OriginAllowList;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses state-changing /api/v1 requests that announce a foreign site, before the firewall can
 * open a session for them (ADR-0013 follow-up: the session cookie carries no CSRF token).
 *
 * The guarded prefix is judged on the path after a single rawurldecode(), mirroring what
 * UrlMatcher::doMatch() routes on: a percent-encoded spelling of /api/v1 reaches the same
 * state-changing endpoint as the plain one, so it is refused the same way. The decode happens
 * exactly once — a second one would diverge from routing in the other direction.
 *
 * Signals are read in this order, and the first one present decides:
 *  1. Origin — trusted when it is the request's own origin or an entry of STOREFRONT_CORS_ORIGIN.
 *  2. Referer — its origin (scheme, host and port, default ports normalised away) is judged by the
 *     same rule; a Referer whose origin cannot be parsed is refused.
 *  3. Sec-Fetch-Site — refused only when it declares "cross-site" (case-insensitive); same-origin,
 *     same-site and none are left for the controller and the firewall to answer.
 * A request announcing none of the three (curl, a provider webhook, server-to-server) is NOT
 * refused: those clients send no browser signal to judge.
 *
 * The refusal is a request listener answering the repo's {"error": ...} 403 shape, like
 * JsonAccessDeniedHandler: no exception is thrown and no header value is logged.
 *
 * This is the request-side control ADR-0013 asked for, not a complete CSRF defence: it carries no
 * CSRF token, and Origin as well as Sec-Fetch-Site are client-controlled, so it is a
 * browser-enforced control, not a defence against non-browser clients.
 */
final class CrossSiteRequestGuardSubscriber implements EventSubscriberInterface
{
    /**
     * Runs after the router listener (priority 32), so the request is already matched, and before
     * the security firewall listener (priority 8), so a refused request never gets authenticated or
     * handed a session. It stays below CorsSubscriber's 256: an OPTIONS preflight is answered by
     * that subscriber first, and this guard never sees it. Priority 14 is shared with no other
     * kernel.request listener, so the ordering stays deliberate instead of accidental.
     */
    private const PRIORITY = 14;

    private const PROTECTED_METHODS = [
        Request::METHOD_POST,
        Request::METHOD_PUT,
        Request::METHOD_PATCH,
        Request::METHOD_DELETE,
    ];

    private readonly OriginAllowList $allowList;

    public function __construct(
        #[Autowire('%env(STOREFRONT_CORS_ORIGIN)%')]
        string $allowedOrigins,
    ) {
        $this->allowList = OriginAllowList::fromCommaSeparated($allowedOrigins);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only main requests reach the firewall and the API controllers.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with(rawurldecode($request->getPathInfo()), '/api/v1')) {
            return;
        }

        if (!\in_array($request->getMethod(), self::PROTECTED_METHODS, true)) {
            return;
        }

        if ($this->isCrossSite($request)) {
            $event->setResponse(new JsonResponse(['error' => 'Cross-site request refused.'], Response::HTTP_FORBIDDEN));
        }
    }

    /**
     * The first signal the request carries decides; one that carries none is not cross-site.
     */
    private function isCrossSite(Request $request): bool
    {
        $origin = $this->header($request, 'Origin');

        if (null !== $origin) {
            $candidate = Origin::fromUrl($origin);

            return null === $candidate || !$this->isTrustedOrigin($candidate, $request);
        }

        $referer = $this->header($request, 'Referer');

        if (null !== $referer) {
            $refererOrigin = Origin::fromUrl($referer);

            return null === $refererOrigin || !$this->isTrustedOrigin($refererOrigin, $request);
        }

        $fetchSite = $this->header($request, 'Sec-Fetch-Site');

        return null !== $fetchSite && 'cross-site' === strtolower($fetchSite);
    }

    /**
     * An absent or empty header carries no signal: it must not shadow the next one in the chain.
     */
    private function header(Request $request, string $name): ?string
    {
        $value = trim((string) $request->headers->get($name, ''));

        return '' === $value ? null : $value;
    }

    /**
     * Trusted means the request's own origin or the configured storefront origin: a same-origin
     * request is not cross-site.
     */
    private function isTrustedOrigin(Origin $origin, Request $request): bool
    {
        $own = Origin::fromUrl($request->getSchemeAndHttpHost());

        if (null !== $own && $origin->equals($own)) {
            return true;
        }

        return $this->allowList->contains($origin);
    }
}
