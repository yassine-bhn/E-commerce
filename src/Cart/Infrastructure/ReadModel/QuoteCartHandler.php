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

        return new CartQuote(
            $result['subtotal'],
            $result['discount'],
            $result['delivery'],
            $result['total'],
            $result['itemCount'],
        );
    }
}
