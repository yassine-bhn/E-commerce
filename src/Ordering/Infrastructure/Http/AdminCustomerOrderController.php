<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http;

use App\Identity\Infrastructure\Security\CustomerUser;
use App\Ordering\Application\Query\ListOrdersForAdminRequestedCustomer;
use App\Ordering\Infrastructure\ReadModel\AdminCustomerOrderView;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Admin dashboard read of one customer's orders (R16), its own controller so that it never
 * touches the WP3 user-administration routes on the same path family.
 *
 * Thin by design (ADR-0007): decode → build the query with the requester identity → dispatch on
 * the query bus → map the view DTOs to the {items: [...]} envelope the admin surface uses.
 * The authorization decision itself lives in the read model (QRY-004), not here.
 */
#[Route('/api/v1/admin/customers')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCustomerOrderController
{
    private const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('/{id}/orders', name: 'admin_customers_list_orders', methods: ['GET'])]
    public function list(string $id, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        if (false === Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid customer UUID'], Response::HTTP_BAD_REQUEST);
        }

        $requesterIsAdmin = [] !== array_intersect(self::ADMIN_ROLES, $admin->getRoles());

        try {
            /** @var list<AdminCustomerOrderView> $orders */
            $orders = $this->queries->dispatch(new ListOrdersForAdminRequestedCustomer(
                requesterId: $admin->getId(),
                requesterIsAdmin: $requesterIsAdmin,
                customerId: $id,
            ));
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof NotFoundHttpException) {
                return new JsonResponse(['error' => 'Customer not found'], Response::HTTP_NOT_FOUND);
            }

            if ($previous instanceof AccessDeniedException) {
                return new JsonResponse(['error' => 'This read requires an admin role'], Response::HTTP_FORBIDDEN);
            }

            throw $exception;
        }

        return new JsonResponse([
            'items' => array_map(static fn (AdminCustomerOrderView $order): array => $order->toArray(), $orders),
        ]);
    }
}
