<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Query\GetStorefrontFaq;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/storefront/faq')]
final class StorefrontFaqController
{
    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('', name: 'storefront_faq', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var list<array{id: string, category: string, question: string, answer: string}> $items */
        $items = $this->queries->dispatch(new GetStorefrontFaq());

        return new JsonResponse(['items' => $items]);
    }
}
