<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http;

use App\Ordering\Application\Command\CreateAdminProxyOrder;
use App\Ordering\Application\Command\TransitionOrderStatus;
use App\Ordering\Domain\Model\IllegalOrderStateTransitionException;
use App\Ordering\Domain\Model\OptimisticLockException;
use App\Ordering\Domain\Model\OrderSource;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Infrastructure\Http\RateLimitChecker;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/admin/orders')]
final class AdminOrderController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private Connection $connection,
        #[Target('admin_actions')]
        private RateLimiterFactoryInterface $adminActionsLimiter,
        private RateLimitChecker $rateLimitChecker,
    ) {
    }

    #[Route('/proxy', name: 'admin_orders_create_proxy', methods: ['POST'])]
    public function createProxy(Request $request): JsonResponse
    {
        if ($limitResponse = $this->rateLimitChecker->check($this->adminActionsLimiter, $request)) {
            return $limitResponse;
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $sourceStr = strtoupper(trim((string) ($body['orderSource'] ?? 'WHATSAPP')));
        $orderSource = OrderSource::tryFrom($sourceStr);
        if (null === $orderSource || OrderSource::ONLINE === $orderSource) {
            return new JsonResponse(['error' => 'orderSource must be either PHONE or WHATSAPP'], Response::HTTP_BAD_REQUEST);
        }

        $customerName = trim((string) ($body['customerName'] ?? ''));
        $customerPhone = trim((string) ($body['customerPhone'] ?? ''));
        $shippingAddress = trim((string) ($body['shippingAddress'] ?? ''));

        if ('' === $customerName || '' === $customerPhone || '' === $shippingAddress) {
            return new JsonResponse(['error' => 'customerName, customerPhone, and shippingAddress are required for proxy orders'], Response::HTTP_BAD_REQUEST);
        }

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

        $orderId = Uuid::v7()->toRfc4122();
        $prefix = OrderSource::WHATSAPP === $orderSource ? 'ZG-WA-' : 'ZG-TEL-';
        $orderNumber = $prefix.random_int(100000, 999999);
        $createdByAdminId = (string) ($request->headers->get('X-Admin-Id') ?? $body['adminId'] ?? 'admin-operator');

        $command = new CreateAdminProxyOrder(
            orderId: $orderId,
            orderNumber: $orderNumber,
            orderSource: $orderSource,
            customerName: $customerName,
            customerPhone: $customerPhone,
            shippingAddress: $shippingAddress,
            lines: $normalizedLines,
            deliveryMethod: (string) ($body['deliveryMethod'] ?? 'home'),
            paymentMethod: (string) ($body['paymentMethod'] ?? 'Virement / Paiement à la livraison'),
            createdByAdminId: $createdByAdminId,
            adminNotes: isset($body['adminNotes']) ? (string) $body['adminNotes'] : null,
            promoCode: isset($body['promoCode']) ? (string) $body['promoCode'] : null,
        );

        $this->commandBus->dispatch($command);

        return new JsonResponse([
            'success' => true,
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'orderSource' => $orderSource->value,
            'status' => OrderStatus::PENDING->value,
            'version' => 1,
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/status', name: 'admin_orders_transition_status', methods: ['PATCH'])]
    public function transitionStatus(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid order UUID'], Response::HTTP_BAD_REQUEST);
        }

        if ($limitResponse = $this->rateLimitChecker->check($this->adminActionsLimiter, $request)) {
            return $limitResponse;
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        if (!isset($body['expectedVersion']) || !is_int($body['expectedVersion'])) {
            return new JsonResponse(['error' => 'expectedVersion (integer) is required for optimistic concurrency control'], Response::HTTP_BAD_REQUEST);
        }

        $targetStatusStr = (string) ($body['targetStatus'] ?? '');
        $targetStatus = OrderStatus::tryFrom($targetStatusStr);
        if (null === $targetStatus) {
            return new JsonResponse([
                'error' => sprintf('Invalid targetStatus. Valid values are: %s', implode(', ', array_map(static fn (OrderStatus $s) => $s->value, OrderStatus::cases()))),
            ], Response::HTTP_BAD_REQUEST);
        }

        $reason = trim((string) ($body['reason'] ?? ''));
        if ('' === $reason) {
            return new JsonResponse(['error' => 'A non-empty reason is required for order status transition'], Response::HTTP_BAD_REQUEST);
        }

        $changedBy = (string) ($request->headers->get('X-Admin-Id') ?? $body['changedBy'] ?? 'admin-system');

        $command = new TransitionOrderStatus(
            orderId: $id,
            expectedVersion: $body['expectedVersion'],
            targetStatus: $targetStatus,
            changedBy: $changedBy,
            reason: $reason,
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof OptimisticLockException) {
                return new JsonResponse([
                    'error' => 'Concurrency Conflict',
                    'message' => $previous->getMessage(),
                ], Response::HTTP_CONFLICT);
            }
            if ($previous instanceof IllegalOrderStateTransitionException) {
                return new JsonResponse([
                    'error' => 'Illegal State Transition',
                    'message' => $previous->getMessage(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse([
            'success' => true,
            'orderId' => $id,
            'newStatus' => $targetStatus->value,
            'version' => $body['expectedVersion'] + 1,
        ]);
    }

    #[Route('/{id}/audit', name: 'admin_orders_audit_trail', methods: ['GET'])]
    public function auditTrail(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid order UUID'], Response::HTTP_BAD_REQUEST);
        }

        $logs = $this->connection->fetchAllAssociative(
            'SELECT from_status, to_status, from_version, to_version, changed_by, reason, created_at
             FROM ordering_order_audit_trail
             WHERE order_id = :id
             ORDER BY to_version ASC',
            ['id' => $id],
        );

        return new JsonResponse(['orderId' => $id, 'auditTrail' => $logs]);
    }
}
