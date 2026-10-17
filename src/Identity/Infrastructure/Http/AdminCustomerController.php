<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\BanCustomer;
use App\Identity\Application\Command\DeleteCustomerWithAnonymization;
use App\Identity\Application\Command\UnbanCustomer;
use App\Identity\Domain\Model\CannotBanAdminException;
use App\Identity\Domain\Model\LastSuperAdminCannotBeRemovedException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/admin/customers')]
final class AdminCustomerController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private Connection $connection,
    ) {
    }

    #[Route('/{id}/ban', name: 'admin_customers_ban', methods: ['POST'])]
    public function ban(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid customer UUID'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $reason = trim((string) ($body['reason'] ?? ''));
        if ('' === $reason) {
            return new JsonResponse(['error' => 'A valid non-empty reason is required to ban a customer'], Response::HTTP_BAD_REQUEST);
        }

        $adminId = (string) ($request->headers->get('X-Admin-Id') ?? $body['adminId'] ?? 'admin-operator');

        try {
            $this->commandBus->dispatch(new BanCustomer(
                customerId: $id,
                adminId: $adminId,
                reason: $reason,
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof CannotBanAdminException) {
                return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_FORBIDDEN);
            }
            throw $e;
        }

        return new JsonResponse(['success' => true, 'customerId' => $id, 'status' => 'banned']);
    }

    #[Route('/{id}/unban', name: 'admin_customers_unban', methods: ['POST'])]
    public function unban(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid customer UUID'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $reason = trim((string) ($body['reason'] ?? 'Administrative review completed'));
        $adminId = (string) ($request->headers->get('X-Admin-Id') ?? $body['adminId'] ?? 'admin-operator');

        $this->commandBus->dispatch(new UnbanCustomer(
            customerId: $id,
            adminId: $adminId,
            reason: $reason,
        ));

        return new JsonResponse(['success' => true, 'customerId' => $id, 'status' => 'active']);
    }

    #[Route('/{id}', name: 'admin_customers_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid customer UUID'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $adminId = (string) ($request->headers->get('X-Admin-Id') ?? $body['adminId'] ?? 'admin-operator');
        $reason = isset($body['reason']) ? (string) $body['reason'] : null;

        try {
            $this->commandBus->dispatch(new DeleteCustomerWithAnonymization(
                customerId: $id,
                adminId: $adminId,
                reason: $reason,
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof LastSuperAdminCannotBeRemovedException) {
                return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_FORBIDDEN);
            }
            throw $e;
        }

        return new JsonResponse(['success' => true, 'customerId' => $id, 'message' => 'Customer processed successfully (deleted or anonymized per RG3.4)']);
    }

    #[Route('/{id}/audit', name: 'admin_customers_audit_trail', methods: ['GET'])]
    public function auditTrail(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid customer UUID'], Response::HTTP_BAD_REQUEST);
        }

        $logs = $this->connection->fetchAllAssociative(
            'SELECT action, actor_admin_id, reason, created_at
             FROM identity_audit_log
             WHERE target_user_id = :id
             ORDER BY created_at ASC',
            ['id' => $id],
        );

        return new JsonResponse(['customerId' => $id, 'auditTrail' => $logs]);
    }
}
