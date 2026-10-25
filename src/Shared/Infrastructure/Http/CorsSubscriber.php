<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Http\OriginAllowList;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The browser's Origin header value is echoed back verbatim in Access-Control-Allow-Origin, so an
 * allow-listed origin is judged on the exact header spelling through OriginAllowList::containsRaw(),
 * never on a normalised form of it.
 */
final class CorsSubscriber implements EventSubscriberInterface
{
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
            KernelEvents::REQUEST => ['onRequest', 256],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        if ('OPTIONS' !== $request->getMethod()) {
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyHeaders($response, $request);
        $event->setResponse($response);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $this->applyHeaders($event->getResponse(), $event->getRequest());
    }

    private function applyHeaders(Response $response, Request $request): void
    {
        $origin = trim((string) $request->headers->get('Origin', ''));

        $response->headers->set('Vary', 'Origin');

        if ('' !== $origin && $this->allowList->containsRaw($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Customer-Id, Idempotency-Key');
    }
}
