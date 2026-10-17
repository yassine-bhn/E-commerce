<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\SubmitProductReview;
use App\Catalog\Application\Query\ListProductReviews;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/storefront/products/{slug}/reviews')]
final class StorefrontReviewController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
    ) {
    }

    #[Route('', name: 'storefront_reviews_list', methods: ['GET'])]
    public function list(string $slug): JsonResponse
    {
        /** @var list<array<string, mixed>> $reviews */
        $reviews = $this->queries->dispatch(new ListProductReviews($slug));

        $count = count($reviews);
        $avg = $count > 0 ? round(array_sum(array_column($reviews, 'rating')) / $count, 1) : 0;

        return new JsonResponse([
            'slug' => $slug,
            'count' => $count,
            'averageRating' => $avg,
            'items' => $reviews,
        ]);
    }

    #[Route('', name: 'storefront_reviews_create', methods: ['POST'])]
    public function create(string $slug, Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $author = is_string($body['author'] ?? null) ? trim($body['author']) : '';
        if ('' === $author) {
            return new JsonResponse(['error' => 'author name required'], Response::HTTP_BAD_REQUEST);
        }

        $comment = is_string($body['comment'] ?? null) ? trim($body['comment']) : '';
        if ('' === $comment) {
            return new JsonResponse(['error' => 'comment required'], Response::HTTP_BAD_REQUEST);
        }

        $rating = is_numeric($body['rating'] ?? null) ? (int) $body['rating'] : 5;
        if ($rating < 1 || $rating > 5) {
            return new JsonResponse(['error' => 'rating must be between 1 and 5'], Response::HTTP_BAD_REQUEST);
        }

        $id = Uuid::v7()->toRfc4122();
        $this->commandBus->dispatch(new SubmitProductReview(
            $id,
            $slug,
            $author,
            $rating,
            $comment,
        ));

        return new JsonResponse(['id' => $id, 'status' => 'created'], Response::HTTP_CREATED);
    }
}
