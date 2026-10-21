<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\BanCustomer;
use App\Identity\Application\Command\ChangeCustomerRole;
use App\Identity\Application\Command\DeleteCustomerWithAnonymization;
use App\Identity\Application\Command\UnbanCustomer;
use App\Identity\Application\Query\ListAdminCustomers;
use App\Identity\Domain\Model\AdminAccessRequiredException;
use App\Identity\Domain\Model\AdminCustomerNotFoundException;
use App\Identity\Domain\Model\CannotBanAdminException;
use App\Identity\Domain\Model\LastSuperAdminCannotBeDemotedException;
use App\Identity\Domain\Model\LastSuperAdminCannotBeRemovedException;
use App\Identity\Domain\Model\RoleNotAssignableException;
use App\Identity\Infrastructure\ReadModel\AdminCustomersPageView;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/admin/customers')]
final class AdminCustomerController
{
    /** Privileged accounts allowed to read the user list (assumption 12 of the spec). */
    private const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
        private Connection $connection,
    ) {
    }

    #[Route('', name: 'admin_customers_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        try {
            /** @var AdminCustomersPageView $page */
            $page = $this->queries->dispatch(new ListAdminCustomers(
                requesterId: $admin->getId(),
                requesterIsAdmin: [] !== array_intersect(self::ADMIN_ROLES, $admin->getRoles()),
                page: $request->query->getInt('page', 1),
                limit: $request->query->getInt('limit', ListAdminCustomers::DEFAULT_LIMIT),
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof AdminAccessRequiredException) {
                return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_FORBIDDEN);
            }

            throw $e;
        }

        return new JsonResponse($page->toArray());
    }

    #[Route('/{id}/ban', name: 'admin_customers_ban', methods: ['POST'])]
    public function ban(string $id, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
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

        $adminId = $admin->getId();

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
    public function unban(string $id, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid customer UUID'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $reason = trim((string) ($body['reason'] ?? 'Administrative review completed'));
        $adminId = $admin->getId();

        $this->commandBus->dispatch(new UnbanCustomer(
            customerId: $id,
            adminId: $adminId,
            reason: $reason,
        ));

        return new JsonResponse(['success' => true, 'customerId' => $id, 'status' => 'active']);
    }

    #[Route('/{id}/role', name: 'admin_customers_role_change', methods: ['PATCH'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function changeRole(string $id, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid customer UUID'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        if (!is_string($body['role'] ?? null)) {
            return new JsonResponse(['error' => 'The role field is required and must be a string'], Response::HTTP_BAD_REQUEST);
        }

        $role = trim($body['role']);

        try {
            $this->commandBus->dispatch(new ChangeCustomerRole(
                customerId: $id,
                adminId: $admin->getId(),
                role: $role,
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof RoleNotAssignableException) {
                return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($previous instanceof LastSuperAdminCannotBeDemotedException) {
                return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_FORBIDDEN);
            }
            if ($previous instanceof AdminCustomerNotFoundException) {
                return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(['success' => true, 'customerId' => $id, 'role' => $role]);
    }

    #[Route('/{id}', name: 'admin_customers_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(string $id, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid customer UUID'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $adminId = $admin->getId();
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
