<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Query\GetStorefrontReference;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/storefront/reference')]
final class StorefrontReferenceController
{
    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('', name: 'storefront_reference', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $this->queries->dispatch(new GetStorefrontReference());

        return new JsonResponse($data);
    }
}
