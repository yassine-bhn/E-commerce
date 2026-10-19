<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\SubmitQuoteRequest;
use App\Identity\Application\Query\ListQuoteRequestsForUser;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/storefront/quote-requests')]
final class QuoteRequestController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
    ) {
    }

    #[Route('', name: 'storefront_quote_requests_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?CustomerUser $user): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        foreach (['full_name', 'email', 'phone', 'category', 'message'] as $field) {
            if (!is_string($body[$field] ?? null) || '' === trim($body[$field])) {
                return new JsonResponse(['error' => $field.' required'], Response::HTTP_BAD_REQUEST);
            }
        }

        $userUuid = $user?->getId();

        /** @var list<string> $subs */
        $subs = is_array($body['subcategories'] ?? null) ? array_values(array_filter($body['subcategories'], 'is_string')) : [];

        try {
            $this->commandBus->dispatch(new SubmitQuoteRequest(
                Uuid::v7()->toRfc4122(),
                $userUuid,
                trim($body['full_name']),
                trim($body['email']),
                trim($body['phone']),
                is_string($body['city'] ?? null) && '' !== $body['city'] ? trim($body['city']) : null,
                trim($body['category']),
                $subs,
                trim($body['message']),
            ));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['status' => 'nouveau'], Response::HTTP_CREATED);
    }

    #[Route('', name: 'storefront_quote_requests_list', methods: ['GET'])]
    public function list(#[CurrentUser] CustomerUser $user): JsonResponse
    {
        $customerId = $user->getId();

        /** @var list<array<string, mixed>> $items */
        $items = $this->queries->dispatch(new ListQuoteRequestsForUser($customerId));

        return new JsonResponse(['items' => $items]);
    }
}
