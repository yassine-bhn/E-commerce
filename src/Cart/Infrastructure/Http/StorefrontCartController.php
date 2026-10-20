<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Http;

use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\Command\ClearCart;
use App\Cart\Application\Command\RemoveCartItem;
use App\Cart\Application\Command\UpdateCartItem;
use App\Cart\Application\Query\GetStorefrontCart;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/storefront/cart')]
final class StorefrontCartController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
    ) {
    }

    #[Route('', name: 'storefront_cart_get', methods: ['GET'])]
    public function get(#[CurrentUser] CustomerUser $user): JsonResponse
    {
        /** @var array{id: ?string, items: list<array<string, mixed>>, itemCount: int} $cart */
        $cart = $this->queries->dispatch(new GetStorefrontCart($user->getId()));

        return new JsonResponse($cart);
    }

    #[Route('/items', name: 'storefront_cart_add_item', methods: ['POST'])]
    public function addItem(Request $request, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $slug = $body['slug'] ?? null;
        if (!is_string($slug) || '' === trim($slug)) {
            return new JsonResponse(['error' => 'slug is required'], Response::HTTP_BAD_REQUEST);
        }

        $variant = isset($body['variant']) && is_string($body['variant']) && '' !== trim($body['variant'])
            ? trim($body['variant'])
            : null;
        $quantity = max(1, (int) ($body['quantity'] ?? $body['qty'] ?? 1));

        try {
            $this->commandBus->dispatch(new AddCartItem($user->getId(), trim($slug), $variant, $quantity));
        } catch (HandlerFailedException $e) {
            $invalidArguments = $e->getWrappedExceptions(\InvalidArgumentException::class, true);
            if ([] === $invalidArguments) {
                throw $e;
            }

            /** @var \InvalidArgumentException $invalidArgument */
            $invalidArgument = reset($invalidArguments);
            $status = str_contains(strtolower($invalidArgument->getMessage()), 'not found')
                ? Response::HTTP_NOT_FOUND
                : Response::HTTP_BAD_REQUEST;

            return new JsonResponse(['error' => $invalidArgument->getMessage()], $status);
        }

        /** @var array{id: ?string, items: list<array<string, mixed>>, itemCount: int} $cart */
        $cart = $this->queries->dispatch(new GetStorefrontCart($user->getId()));
        $item = $this->findMatchingItem($cart['items'], trim($slug), $variant);

        return new JsonResponse([
            'item' => $item,
            'cart' => $cart,
        ], Response::HTTP_CREATED);
    }

    #[Route('/items/{itemId}', name: 'storefront_cart_update_item', methods: ['PATCH'])]
    public function updateItem(string $itemId, Request $request, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $quantity = array_key_exists('quantity', $body) || array_key_exists('qty', $body)
            ? max(1, (int) ($body['quantity'] ?? $body['qty'] ?? 1))
            : null;
        $variant = array_key_exists('variant', $body)
            ? (is_string($body['variant']) && '' !== trim($body['variant']) ? trim($body['variant']) : null)
            : null;

        if (null === $quantity && null === $variant && !array_key_exists('variant', $body)) {
            return new JsonResponse(['error' => 'quantity or variant required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new UpdateCartItem($user->getId(), $itemId, $quantity, $variant));
        } catch (HandlerFailedException $e) {
            $invalidArguments = $e->getWrappedExceptions(\InvalidArgumentException::class, true);
            if ([] === $invalidArguments) {
                throw $e;
            }

            /** @var \InvalidArgumentException $invalidArgument */
            $invalidArgument = reset($invalidArguments);
            $status = str_contains(strtolower($invalidArgument->getMessage()), 'not found')
                ? Response::HTTP_NOT_FOUND
                : Response::HTTP_BAD_REQUEST;

            return new JsonResponse(['error' => $invalidArgument->getMessage()], $status);
        }

        /** @var array{id: ?string, items: list<array<string, mixed>>, itemCount: int} $cart */
        $cart = $this->queries->dispatch(new GetStorefrontCart($user->getId()));
        $item = $this->findItemById($cart['items'], $itemId);

        return new JsonResponse([
            'item' => $item,
            'cart' => $cart,
        ]);
    }

    #[Route('/items/{itemId}', name: 'storefront_cart_remove_item', methods: ['DELETE'])]
    public function removeItem(string $itemId, #[CurrentUser] CustomerUser $user): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new RemoveCartItem($user->getId(), $itemId));
        } catch (HandlerFailedException $e) {
            $invalidArguments = $e->getWrappedExceptions(\InvalidArgumentException::class, true);
            if ([] === $invalidArguments) {
                throw $e;
            }

            /** @var \InvalidArgumentException $invalidArgument */
            $invalidArgument = reset($invalidArguments);

            return new JsonResponse(['error' => $invalidArgument->getMessage()], Response::HTTP_NOT_FOUND);
        }

        /** @var array{id: ?string, items: list<array<string, mixed>>, itemCount: int} $cart */
        $cart = $this->queries->dispatch(new GetStorefrontCart($user->getId()));

        return new JsonResponse($cart);
    }

    #[Route('', name: 'storefront_cart_clear', methods: ['DELETE'])]
    public function clear(#[CurrentUser] CustomerUser $user): JsonResponse
    {
        $this->commandBus->dispatch(new ClearCart($user->getId()));

        return new JsonResponse([
            'id' => null,
            'items' => [],
            'itemCount' => 0,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>|null
     */
    private function findMatchingItem(array $items, string $slug, ?string $variant): ?array
    {
        foreach ($items as $item) {
            if ($item['slug'] !== $slug) {
                continue;
            }
            $itemVariant = $item['variant'] ?? null;
            if ($itemVariant === $variant) {
                return $item;
            }
        }

        return $items[array_key_last($items)] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>|null
     */
    private function findItemById(array $items, string $itemId): ?array
    {
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $itemId) {
                return $item;
            }
        }

        return null;
    }
}
