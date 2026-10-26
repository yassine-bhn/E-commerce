<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Query\ListAdminProducts;
use App\Identity\Infrastructure\Security\CustomerUser;
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
 * Dashboard list of every product (any approval / publication state), with filters, sort and
 * pagination. Its own controller so it never touches {@see AdminProductController}. Thin by design
 * (ADR-0007): decode → build the query with the requester identity → dispatch on the query bus →
 * answer the `{items, meta}` envelope. The authorization decision lives in the read model (QRY-004).
 *
 * Query string: `page`, `size` (20 by default, 50 at most), `q` (name, slug, reference or brand),
 * `category`, `sub`, `brand`, `stock` (in|order|pickup|out), `approval` (pending|approved|rejected),
 * `published` (true|false), `sort` (name|priceAsc|priceDesc|stockAsc|recent|approval).
 */
#[Route('/api/v1/admin/catalog/products')]
#[IsGranted('ROLE_ADMIN')]
final class AdminProductListController
{
    private const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('', name: 'admin_catalog_list_products', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        try {
            /** @var Page $page */
            $page = $this->queries->dispatch(new ListAdminProducts(
                requesterId: $admin->getId(),
                requesterIsAdmin: [] !== array_intersect(self::ADMIN_ROLES, $admin->getRoles()),
                page: ListQueryParams::page($request, ListAdminProducts::DEFAULT_SIZE, ListAdminProducts::MAX_SIZE),
                q: ListQueryParams::text($request),
                category: ListQueryParams::text($request, 'category'),
                subcategory: ListQueryParams::text($request, 'sub'),
                brand: ListQueryParams::text($request, 'brand'),
                stock: ListQueryParams::text($request, 'stock'),
                approval: ListQueryParams::text($request, 'approval'),
                published: ListQueryParams::boolOrNull($request, 'published'),
                sort: ListQueryParams::text($request, 'sort'),
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof AccessDeniedException) {
                return new JsonResponse(['error' => 'This read requires a back-office role'], Response::HTTP_FORBIDDEN);
            }

            throw $exception;
        }

        return new JsonResponse($page->toArray());
    }
}
