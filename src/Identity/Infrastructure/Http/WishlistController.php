<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Catalog\Infrastructure\ReadModel\StorefrontProductRepository;
use App\Identity\Application\Command\AddWishlistItem;
use App\Identity\Application\Command\RemoveWishlistItem;
use App\Identity\Application\Query\ListWishlistItems;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Application\Pagination\Page;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/storefront/wishlist')]
final class WishlistController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
        private StorefrontProductRepository $products,
    ) {
    }

    #[Route('', name: 'storefront_wishlist_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        /** @var Page $page */
        $page = $this->queries->dispatch(new ListWishlistItems(
            $user->getId(),
            ListQueryParams::page($request, ListWishlistItems::DEFAULT_SIZE, ListWishlistItems::MAX_SIZE),
            ListQueryParams::text($request),
            ListQueryParams::text($request, 'sort'),
        ));

        return new JsonResponse($page->toArray());
    }

    #[Route('', name: 'storefront_wishlist_add', methods: ['POST'])]
    public function add(Request $request, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $slug = $body['slug'] ?? null;
        if (!is_string($slug) || '' === trim($slug)) {
            return new JsonResponse(['error' => 'slug is required'], Response::HTTP_BAD_REQUEST);
        }
        $slug = trim($slug);

        if (null === $this->products->bySlug($slug)) {
            return new JsonResponse(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        $this->commandBus->dispatch(new AddWishlistItem($user->getId(), $slug));

        return new JsonResponse(['status' => 'added', 'slug' => $slug], Response::HTTP_CREATED);
    }

    #[Route('/{slug}', name: 'storefront_wishlist_remove', methods: ['DELETE'])]
    public function remove(string $slug, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        $this->commandBus->dispatch(new RemoveWishlistItem($user->getId(), $slug));

        return new JsonResponse(['status' => 'removed']);
    }
}
