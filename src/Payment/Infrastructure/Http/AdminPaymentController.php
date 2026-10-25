<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Http;

use App\Identity\Infrastructure\Security\CustomerUser;
use App\Payment\Application\Command\ConfirmOfflinePayment;
use App\Payment\Application\Command\RefundPayment;
use App\Payment\Application\Query\GetPaymentDetail;
use App\Payment\Domain\Model\IllegalPaymentStateTransitionException;
use App\Payment\Domain\Model\PaymentNotFoundException;
use App\Payment\Domain\Model\PaymentVersionConflictException;
use App\Shared\Domain\Payment\OrderNotPayableException;
use App\Shared\Domain\Payment\OrderNotRefundableException;
use App\Shared\Infrastructure\Http\MessengerQuery;
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
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/admin/payments')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPaymentController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private Connection $connection,
        #[Target('admin_actions')]
        private RateLimiterFactoryInterface $adminActionsLimiter,
        private RateLimitChecker $rateLimitChecker,
        private MessengerQuery $queries,
    ) {
    }

    /**
     * The operator audits the money: the current state plus the whole ledger, because a
     * payment can be confirmed and refunded and only the history says what happened.
     */
    #[Route('/{id}', name: 'admin_payments_show', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid payment UUID'], Response::HTTP_BAD_REQUEST);
        }

        $payment = $this->queries->dispatch(new GetPaymentDetail($id, $admin->getId(), true));

        if (!is_array($payment)) {
            return new JsonResponse(['error' => 'payment-not-found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($payment);
    }

    /**
     * BRS §三.1.6-7 — offline receipts (bank transfer, cash on delivery) are confirmed by the
     * operator; no gateway is called, the confirmation itself is the authorization.
     */
    #[Route('/{id}/confirm', name: 'admin_payments_confirm', methods: ['POST'])]
    public function confirm(string $id, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid payment UUID'], Response::HTTP_BAD_REQUEST);
        }

        if ($limitResponse = $this->rateLimitChecker->check($this->adminActionsLimiter, $request)) {
            return $limitResponse;
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        if (!isset($body['expectedVersion']) || !is_int($body['expectedVersion'])) {
            return new JsonResponse(
                ['error' => 'expectedVersion (integer) is required for optimistic concurrency control'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $reference = isset($body['reference']) ? trim((string) $body['reference']) : null;
        if ('' === $reference) {
            $reference = null;
        }
        if (null !== $reference && mb_strlen($reference) > 128) {
            return new JsonResponse(['error' => 'reference must be at most 128 characters'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new ConfirmOfflinePayment($id, $body['expectedVersion'], $admin->getId(), $reference));
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();

            if ($cause instanceof PaymentNotFoundException) {
                return new JsonResponse(['error' => 'payment-not-found'], Response::HTTP_NOT_FOUND);
            }
            if ($cause instanceof PaymentVersionConflictException) {
                return new JsonResponse(['error' => 'payment-version-conflict', 'message' => $cause->getMessage()], Response::HTTP_CONFLICT);
            }
            if ($cause instanceof IllegalPaymentStateTransitionException) {
                return new JsonResponse(['error' => 'payment-not-confirmable', 'message' => $cause->getMessage()], Response::HTTP_CONFLICT);
            }
            if ($cause instanceof OrderNotPayableException) {
                return new JsonResponse(['error' => 'order-not-payable', 'message' => $cause->getMessage()], Response::HTTP_CONFLICT);
            }

            throw $e;
        }

        return new JsonResponse($this->state($id));
    }

    /**
     * BRS §七 RC8 — paid then cancelled has to give the money back; the refund is recorded like the
     * confirmation, with the operator's reason on the ledger rather than overwriting the debt.
     */
    #[Route('/{id}/refund', name: 'admin_payments_refund', methods: ['POST'])]
    public function refund(string $id, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid payment UUID'], Response::HTTP_BAD_REQUEST);
        }

        if ($limitResponse = $this->rateLimitChecker->check($this->adminActionsLimiter, $request)) {
            return $limitResponse;
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        if (!isset($body['expectedVersion']) || !is_int($body['expectedVersion'])) {
            return new JsonResponse(
                ['error' => 'expectedVersion (integer) is required for optimistic concurrency control'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        if ('' === $reason) {
            return new JsonResponse(['error' => 'A non-empty reason is required for a refund'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($reason) > 255) {
            return new JsonResponse(['error' => 'reason must be at most 255 characters'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new RefundPayment($id, $body['expectedVersion'], $admin->getId(), $reason));
        } catch (HandlerFailedException $e) {
            $cause = $e->getPrevious();

            if ($cause instanceof PaymentNotFoundException) {
                return new JsonResponse(['error' => 'payment-not-found'], Response::HTTP_NOT_FOUND);
            }
            if ($cause instanceof PaymentVersionConflictException) {
                return new JsonResponse(['error' => 'payment-version-conflict', 'message' => $cause->getMessage()], Response::HTTP_CONFLICT);
            }
            if ($cause instanceof IllegalPaymentStateTransitionException) {
                return new JsonResponse(['error' => 'payment-not-refundable', 'message' => $cause->getMessage()], Response::HTTP_CONFLICT);
            }
            if ($cause instanceof OrderNotRefundableException) {
                return new JsonResponse(['error' => 'order-not-refundable', 'message' => $cause->getMessage()], Response::HTTP_CONFLICT);
            }

            throw $e;
        }

        return new JsonResponse($this->state($id));
    }

    /** @return array{paymentId: string, status: string, version: int, orderStatus: string} */
    private function state(string $paymentId): array
    {
        $payment = $this->connection->fetchAssociative(
            'SELECT p.status, p.version, o.status AS order_status
             FROM payment p JOIN ordering_orders o ON o.id = p.order_id
             WHERE p.id = :id',
            ['id' => $paymentId],
        );

        if (false === $payment) {
            return ['paymentId' => $paymentId, 'status' => 'unknown', 'version' => 0, 'orderStatus' => 'unknown'];
        }

        return [
            'paymentId' => $paymentId,
            'status' => (string) $payment['status'],
            'version' => (int) $payment['version'],
            'orderStatus' => (string) $payment['order_status'],
        ];
    }
}
