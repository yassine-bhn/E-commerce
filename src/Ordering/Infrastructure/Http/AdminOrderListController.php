<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http;

use App\Identity\Infrastructure\Security\CustomerUser;
use App\Ordering\Application\Query\ListAdminOrders;
use App\Shared\Application\Pagination\Page;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dashboard list of every order, with filters, sort and pagination.
 *
 * Its own controller so it never touches {@see AdminOrderController} (proxy order creation, status
 * transition, audit trail) on the same path family. Thin by design (ADR-0007): decode → build the
 * query with the requester identity → dispatch on the query bus → answer the `{items, meta}`
 * envelope. The authorization decision itself lives in the read model (QRY-004).
 *
 * Query string: `page`, `size` (20 by default, 50 at most), `q` (order number, customer name,
 * email or phone), `status` (pending|paid|failed|cancelled|shipped|refunded), `source`
 * (ONLINE|PHONE|WHATSAPP), `dateFrom` / `dateTo` (Y-m-d), `sort` (newest|oldest|totalDesc|totalAsc).
 */
#[Route('/api/v1/admin/orders')]
#[IsGranted('ROLE_ADMIN')]
final class AdminOrderListController
{
    private const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('', name: 'admin_orders_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        try {
            /** @var Page $page */
            $page = $this->queries->dispatch(new ListAdminOrders(
                requesterId: $admin->getId(),
                requesterIsAdmin: [] !== array_intersect(self::ADMIN_ROLES, $admin->getRoles()),
                page: ListQueryParams::page($request, ListAdminOrders::DEFAULT_SIZE, ListAdminOrders::MAX_SIZE),
                q: ListQueryParams::text($request),
                status: ListQueryParams::text($request, 'status'),
                source: ListQueryParams::text($request, 'source'),
                dateFrom: ListQueryParams::dateOrNull($request, 'dateFrom'),
                dateTo: ListQueryParams::dateOrNull($request, 'dateTo'),
                sort: ListQueryParams::text($request, 'sort'),
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof AccessDeniedException) {
                return new JsonResponse(['error' => 'This read requires an admin role'], Response::HTTP_FORBIDDEN);
            }

            throw $exception;
        }

        return new JsonResponse($page->toArray());
    }
}
