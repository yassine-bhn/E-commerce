<?php

declare(strict_types=1);

namespace App\Promotions\Infrastructure\Http;

use App\Promotions\Application\Query\ValidatePromoCode;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/storefront/promotions')]
final class StorefrontPromoController
{
    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('/validate', name: 'storefront_promo_validate', methods: ['GET', 'POST'])]
    public function validate(Request $request): JsonResponse
    {
        $code = $request->query->getString('code');
        if ('' === $code && 'POST' === $request->getMethod()) {
            /** @var array<string, mixed> $body */
            $body = json_decode($request->getContent(), true) ?? [];
            $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        }

        /** @var array<string, mixed> $result */
        $result = $this->queries->dispatch(new ValidatePromoCode($code));

        return new JsonResponse($result);
    }
}
