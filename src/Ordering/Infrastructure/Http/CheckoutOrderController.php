<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http;

use App\Identity\Infrastructure\Security\CustomerUser;
use App\Ordering\Application\Command\CancelOrderByCustomer;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\Query\ListOrdersForCustomer;
use App\Ordering\Application\Saga\CheckoutSaga;
use App\Ordering\Domain\Model\IllegalOrderStateTransitionException;
use App\Ordering\Domain\Model\OptimisticLockException;
use App\Ordering\Domain\Model\OrderNotFoundException;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Application\Pagination\CursorPage;
use App\Shared\Application\Pagination\InvalidCursorException;
use App\Shared\Domain\Inventory\InsufficientStockException;
use App\Shared\Infrastructure\Http\LineQuantity;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use App\Shared\Infrastructure\Http\RateLimitChecker;
use App\Shared\Infrastructure\ReadModel\PlatformSettingsRepository;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/storefront/orders')]
final class CheckoutOrderController
{
    public function __construct(
        private MessengerQuery $queries,
        private CheckoutSaga $checkout,
        private MessageBusInterface $commandBus,
        #[Target('order_creation')]
        private RateLimiterFactoryInterface $orderCreationLimiter,
        private RateLimitChecker $rateLimitChecker,
        private PlatformSettingsRepository $settings,
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
        $normalizedLines = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                return new JsonResponse(['error' => 'each line must be an object'], Response::HTTP_BAD_REQUEST);
            }

            $slug = $line['slug'] ?? null;
            $quantity = LineQuantity::of($line);
            if (!is_string($slug) || '' === $slug || null === $quantity) {
                return new JsonResponse(
                    ['error' => 'each line requires a slug and a positive integer quantity'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $normalizedLines[] = [
                'slug' => $slug,
                'variant' => isset($line['variant']) && is_string($line['variant']) ? $line['variant'] : null,
                'quantity' => $quantity,
            ];
        }

        $deliveryMethod = is_string($body['deliveryMethod'] ?? null) ? $body['deliveryMethod'] : 'home';
        $paymentMethod = is_string($body['paymentMethod'] ?? null) ? trim($body['paymentMethod']) : '';
        if ('' === $paymentMethod) {
            return new JsonResponse(['error' => 'paymentMethod required'], Response::HTTP_BAD_REQUEST);
        }

        // O-19 — checkout only accepts a method the operator enabled; a free string would let the
        // storefront promise a payment route nobody supports.
        $allowedMethods = $this->settings->paymentMethods();
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            return new JsonResponse([
                'error' => 'payment-method-not-allowed',
                'message' => sprintf('paymentMethod must be one of: %s', implode(', ', $allowedMethods)),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
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
        } catch (InsufficientStockException $e) {
            return $this->insufficientStock($e);
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();
            if ($cause instanceof InsufficientStockException) {
                return $this->insufficientStock($cause);
            }
            if ($cause instanceof \InvalidArgumentException) {
                return new JsonResponse(['error' => $cause->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            throw $e;
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'status' => 'En préparation',
        ], Response::HTTP_CREATED);
    }

    private function insufficientStock(InsufficientStockException $e): JsonResponse
    {
        return new JsonResponse(['error' => 'insufficient-stock', 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
    }

    #[Route('/{id}/cancel', name: 'storefront_orders_cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid order UUID'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        if (!isset($body['expectedVersion']) || !is_int($body['expectedVersion'])) {
            return new JsonResponse(['error' => 'expectedVersion (integer) is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new CancelOrderByCustomer($id, $user->getId(), $body['expectedVersion']));
        } catch (HandlerFailedException $e) {
            $cause = $this->rootCause($e);
            if ($cause instanceof OrderNotFoundException) {
                return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
            }
            if ($cause instanceof AccessDeniedException) {
                return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
            }
            if ($cause instanceof OptimisticLockException) {
                return new JsonResponse(['error' => 'Concurrency Conflict', 'message' => $cause->getMessage()], Response::HTTP_CONFLICT);
            }
            if ($cause instanceof IllegalOrderStateTransitionException) {
                return new JsonResponse(['error' => 'Illegal State Transition', 'message' => $cause->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $e;
        }

        return new JsonResponse([
            'orderId' => $id,
            'status' => OrderStatus::CANCELLED->value,
            'version' => $body['expectedVersion'] + 1,
        ]);
    }

    private function rootCause(\Throwable $e): \Throwable
    {
        while ($e instanceof HandlerFailedException && null !== $e->getPrevious()) {
            $e = $e->getPrevious();
        }

        return $e;
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
