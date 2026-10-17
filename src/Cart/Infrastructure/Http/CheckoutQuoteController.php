<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Http;

use App\Cart\Application\Dto\CartQuote;
use App\Cart\Application\Query\QuoteCart;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/storefront/cart')]
final class CheckoutQuoteController
{
    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('/quote', name: 'storefront_cart_quote', methods: ['POST'])]
    public function quote(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $lines = $body['lines'] ?? null;
        if (!is_array($lines) || [] === $lines) {
            return new JsonResponse(['error' => 'lines required'], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<array{slug: string, variant?: string|null, quantity: int}> $normalizedLines */
        $normalizedLines = array_values(array_map(static fn (array $l): array => [
            'slug' => (string) ($l['slug'] ?? ''),
            'variant' => isset($l['variant']) && is_string($l['variant']) ? $l['variant'] : null,
            'quantity' => max(1, (int) ($l['quantity'] ?? $l['qty'] ?? 1)),
        ], array_filter($lines, 'is_array')));

        $deliveryMethod = is_string($body['deliveryMethod'] ?? null) ? $body['deliveryMethod'] : 'home';
        if (!in_array($deliveryMethod, ['home', 'pickup'], true)) {
            return new JsonResponse(['error' => 'Invalid deliveryMethod'], Response::HTTP_BAD_REQUEST);
        }

        $promoCode = is_string($body['promoCode'] ?? null) ? $body['promoCode'] : null;

        try {
            /** @var CartQuote $quote */
            $quote = $this->queries->dispatch(new QuoteCart($normalizedLines, $promoCode, $deliveryMethod));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'count' => $quote->itemCount,
            'subtotal' => $quote->subtotal->minorUnits(),
            'discount' => $quote->discount->minorUnits(),
            'delivery' => $quote->delivery->minorUnits(),
            'total' => $quote->total->minorUnits(),
            'currency' => $quote->total->currency()->value,
        ]);
    }
}
