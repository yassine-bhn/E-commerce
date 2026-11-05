<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\ReadModel;

use App\Cart\Application\Dto\CartQuote;
use App\Cart\Application\Query\QuoteCart;
use App\Shared\Application\Service\StorefrontQuoteService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class QuoteCartHandler
{
    public function __construct(private StorefrontQuoteService $quotes)
    {
    }

    public function __invoke(QuoteCart $query): CartQuote
    {
        $result = $this->quotes->quote($query->lines, $query->promoCode, $query->deliveryMethod);

        $lines = array_map(
            static fn (array $line): array => [
                'slug' => $line['slug'],
                'variant' => $line['variant'],
                'unitPrice' => $line['unitPriceMinor'],
                'quantity' => $line['quantity'],
            ],
            $result['pricedLines'],
        );

        return new CartQuote(
            $result['subtotal'],
            $result['discount'],
            $result['delivery'],
            $result['total'],
            $result['itemCount'],
            $lines,
            $query->promoCode,
            $query->deliveryMethod,
        );
    }
}
