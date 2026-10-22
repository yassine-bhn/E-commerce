<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http;

use App\Identity\Infrastructure\Security\CustomerUser;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\Query\ListOrdersForCustomer;
use App\Ordering\Application\Saga\CheckoutSaga;
use App\Shared\Application\Pagination\CursorPage;
use App\Shared\Application\Pagination\InvalidCursorException;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use App\Shared\Infrastructure\Http\RateLimitChecker;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/storefront/orders')]
final class CheckoutOrderController
{
    public function __construct(
        private MessengerQuery $queries,
        private CheckoutSaga $checkout,
        #[Target('order_creation')]
        private RateLimiterFactoryInterface $orderCreationLimiter,
        private RateLimitChecker $rateLimitChecker,
    ) {
    }

    #[Route('', name: 'storefront_orders_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        $customerId = $user->getId();

        if ($limitResponse = $this->rateLimitChecker->check($this->orderCreationLimiter, $request, $customerId)) {
            return $limitResponse;
        }

        $idempotencyKey = $request->headers->get('Idempotency-Key') ?? Uuid::v7()->toRfc4122();

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $lines = $body['lines'] ?? null;
        if (!is_array($lines) || [] === $lines) {
            return new JsonResponse(['error' => 'lines required'], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<array{slug: string, variant?: string|null, quantity: int}> $normalizedLines */
        $normalizedLines = array_values(array_map(static fn (array $l): array => [
            'slug' => (string) ($l['slug'] ?? ''),
            'variant' => isset($l['variant']) && is_string($l['variant']) ? $l['variant'] : null,
            'quantity' => max(1, (int) ($l['quantity'] ?? $l['qty'] ?? 1)),
        ], array_filter($lines, 'is_array')));

        $deliveryMethod = is_string($body['deliveryMethod'] ?? null) ? $body['deliveryMethod'] : 'home';
        $paymentMethod = is_string($body['paymentMethod'] ?? null) ? trim($body['paymentMethod']) : '';
        if ('' === $paymentMethod) {
            return new JsonResponse(['error' => 'paymentMethod required'], Response::HTTP_BAD_REQUEST);
        }

        $promoCode = is_string($body['promoCode'] ?? null) ? $body['promoCode'] : null;
        $orderId = Uuid::v7()->toRfc4122();
        $orderNumber = 'ZG-'.random_int(100000, 999999);

        try {
            $this->checkout->startCheckout(new PlaceOrder(
                $orderId,
                $customerId,
                $idempotencyKey,
                $orderNumber,
                $normalizedLines,
                $deliveryMethod,
                $paymentMethod,
                $promoCode,
            ));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'status' => 'En préparation',
        ], Response::HTTP_CREATED);
    }

    #[Route('', name: 'storefront_orders_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        try {
            /** @var CursorPage $page */
            $page = $this->queries->dispatch(new ListOrdersForCustomer(
                customerId: $user->getId(),
                cursor: ListQueryParams::cursor($request, ListOrdersForCustomer::DEFAULT_LIMIT, ListOrdersForCustomer::MAX_LIMIT),
                status: ListQueryParams::text($request, 'status'),
                dateFrom: ListQueryParams::dateOrNull($request, 'dateFrom'),
                dateTo: ListQueryParams::dateOrNull($request, 'dateTo'),
                q: ListQueryParams::text($request),
            ));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof InvalidCursorException) {
                return new JsonResponse(['error' => 'Invalid cursor'], Response::HTTP_BAD_REQUEST);
            }

            throw $e;
        }

        return new JsonResponse($page->toArray());
    }

    #[Route('/track/{orderNumber}', name: 'storefront_orders_track', methods: ['GET'])]
    public function track(string $orderNumber): JsonResponse
    {
        /** @var array<string, mixed>|null $order */
        $order = $this->queries->dispatch(new \App\Ordering\Application\Query\GetStorefrontOrder($orderNumber, null));
        if (null === $order) {
            return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($order);
    }

    #[Route('/{idOrNumber}', name: 'storefront_orders_show', methods: ['GET'])]
    public function show(string $idOrNumber, #[CurrentUser] ?CustomerUser $user): JsonResponse
    {
        $customerUuid = $user?->getId();

        /** @var array<string, mixed>|null $order */
        $order = $this->queries->dispatch(new \App\Ordering\Application\Query\GetStorefrontOrder($idOrNumber, $customerUuid));
        if (null === $order) {
            return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($order);
    }
}
