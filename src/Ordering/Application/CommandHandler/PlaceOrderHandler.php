<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\Port\OrderPlacementStore;
use App\Ordering\Domain\Model\Order;
use App\Ordering\Domain\Model\OrderId;
use App\Ordering\Domain\Model\OrderLine;
use App\Ordering\Domain\Model\PriceChangedException;
use App\Shared\Application\Port\PaymentInitiationPort;
use App\Shared\Application\Port\StockReservationPort;
use App\Shared\Application\Service\StorefrontQuoteService;
use App\Shared\Domain\Money;
use App\Shared\Domain\Outbox\OutboxPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class PlaceOrderHandler
{
    public function __construct(
        private StorefrontQuoteService $quotes,
        private StockReservationPort $stock,
        private PaymentInitiationPort $payments,
        private OrderPlacementStore $placements,
        private OutboxPort $outbox,
    ) {
    }

    public function __invoke(PlaceOrder $command): void
    {
        if (!$this->placements->claim($command->idempotencyKey)) {
            return;
        }

        $quote = $this->quotes->quote($command->lines, $command->promoCode, $command->deliveryMethod);

        $currentQuote = [
            'lines' => array_map(
                static fn (array $line): array => [
                    'slug' => $line['slug'],
                    'variant' => $line['variant'],
                    'unitPrice' => $line['unitPriceMinor'],
                    'quantity' => $line['quantity'],
                ],
                $quote['pricedLines'],
            ),
            'subtotal' => $quote['subtotal']->minorUnits(),
            'discount' => $quote['discount']->minorUnits(),
            'delivery' => $quote['delivery']->minorUnits(),
            'total' => $quote['total']->minorUnits(),
            'count' => $quote['itemCount'],
            'promoCode' => $command->promoCode,
            'deliveryMethod' => $command->deliveryMethod,
        ];

        $changedLines = $this->computeChangedLines($command->confirmedQuote, $quote['pricedLines']);
        $hasLineDiff = [] !== $changedLines;
        $hasScalarDiff = $this->scalarsDiffer($command->confirmedQuote, $command, $currentQuote);

        if (null === $command->confirmedQuote || $hasLineDiff || $hasScalarDiff) {
            throw new PriceChangedException($currentQuote, $changedLines);
        }

        $this->stock->reserve(
            $command->orderId,
            array_map(
                static fn (array $line): array => [
                    'slug' => (string) $line['slug'],
                    'quantity' => (int) $line['quantity'],
                ],
                $quote['pricedLines'],
            ),
        );

        $currency = $quote['total']->currency();

        $lines = array_map(
            static fn (array $line): OrderLine => OrderLine::snapshot(
                Uuid::v7()->toRfc4122(),
                $line['slug'],
                $line['name'],
                $line['variant'],
                Money::ofMinor($line['unitPriceMinor'], $currency),
                $line['quantity'],
            ),
            $quote['pricedLines'],
        );

        $order = Order::placeOnline(
            new OrderId($command->orderId),
            $command->customerId,
            $command->orderNumber,
            $quote['subtotal'],
            $quote['discount'],
            $quote['delivery'],
            $quote['total'],
            $command->deliveryMethod,
            $command->paymentMethod,
            $command->promoCode,
            $lines,
            new \DateTimeImmutable(),
        );

        $this->placements->save($order);

        // ADR-0004 — only the *record* is synchronous; authorizing the card stays off the
        // request path, so the customer never waits on a gateway to get an order number.
        $this->payments->open(
            $command->orderId,
            $command->paymentMethod,
            $quote['total']->minorUnits(),
            $currency->value,
            $command->customerId,
        );

        $this->outbox->append(Order::class, $command->orderId, $order->releaseEvents());
    }

    /**
     * @param array<string, mixed>|null        $confirmed
     * @param array<int, array<string, mixed>> $currentPricedLines
     *
     * @return list<array{slug: string, variant: ?string, previousUnitPrice: ?int, currentUnitPrice: ?int, previousQuantity: ?int, currentQuantity: ?int}>
     */
    private function computeChangedLines(?array $confirmed, array $currentPricedLines): array
    {
        $currentLines = array_map(
            static fn (array $line): array => [
                'slug' => $line['slug'],
                'variant' => $line['variant'],
                'quantity' => $line['quantity'],
                'unitPrice' => $line['unitPriceMinor'],
            ],
            $currentPricedLines,
        );

        $confirmedLines = [];
        if (is_array($confirmed) && isset($confirmed['lines']) && is_array($confirmed['lines'])) {
            $confirmedLines = $confirmed['lines'];
        }

        $confirmedGroups = $this->groupByKey($confirmedLines);
        $currentGroups = $this->groupByKey($currentLines);

        $changed = [];
        $keys = array_values(array_unique(array_merge(array_keys($confirmedGroups), array_keys($currentGroups))));
        foreach ($keys as $key) {
            $c = $confirmedGroups[$key] ?? null;
            $cur = $currentGroups[$key] ?? null;

            $slug = is_array($c) ? $c['slug'] : ($cur['slug'] ?? '');
            $variant = is_array($c) ? $c['variant'] : ($cur['variant'] ?? null);
            $prevPrice = is_array($c) ? $c['unitPrice'] : null;
            $curPrice = is_array($cur) ? $cur['unitPrice'] : null;
            $prevQty = is_array($c) ? $c['quantity'] : null;
            $curQty = is_array($cur) ? $cur['quantity'] : null;

            $differs = (null === $c || null === $cur)
                || $c['quantity'] !== $cur['quantity']
                || $c['unitPrice'] !== $cur['unitPrice'];

            if ($differs) {
                $changed[] = [
                    'slug' => $slug,
                    'variant' => $this->normaliseVariant($variant),
                    'previousUnitPrice' => $prevPrice,
                    'currentUnitPrice' => $curPrice,
                    'previousQuantity' => $prevQty,
                    'currentQuantity' => $curQty,
                ];
            }
        }

        usort($changed, static function (array $a, array $b): int {
            $cmp = strcmp((string) $a['slug'], (string) $b['slug']);
            if (0 !== $cmp) {
                return $cmp;
            }

            return strcmp((string) ($a['variant'] ?? ''), (string) ($b['variant'] ?? ''));
        });

        return $changed;
    }

    /**
     * @param array<int, mixed> $lines
     *
     * @return array<string, array{slug: string, variant: ?string, quantity: int, unitPrice: int}>
     */
    private function groupByKey(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $slug = (string) ($line['slug'] ?? '');
            $variant = $this->normaliseVariant($line['variant'] ?? null);
            $qty = (int) ($line['quantity'] ?? $line['qty'] ?? 0);
            $price = (int) ($line['unitPrice'] ?? $line['unitPriceMinor'] ?? 0);
            $key = $slug."\x1f".($variant ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'slug' => $slug,
                    'variant' => $variant,
                    'quantity' => 0,
                    'unitPrice' => $price,
                ];
            }
            $groups[$key]['quantity'] += $qty;
        }

        return $groups;
    }

    private function normaliseVariant(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @param array<string, mixed>|null $confirmed
     * @param array<string, mixed>      $currentQuote
     */
    private function scalarsDiffer(?array $confirmed, PlaceOrder $command, array $currentQuote): bool
    {
        if (null === $confirmed) {
            return true;
        }

        $confirmedPromo = array_key_exists('promoCode', $confirmed)
            ? $this->nullableString($confirmed['promoCode'])
            : null;
        $confirmedDelivery = array_key_exists('deliveryMethod', $confirmed) && is_string($confirmed['deliveryMethod'])
            ? $confirmed['deliveryMethod']
            : null;

        $confirmedSubtotal = $this->intOrNull($confirmed['subtotal'] ?? null);
        $confirmedDiscount = $this->intOrNull($confirmed['discount'] ?? null);
        $confirmedDeliveryFee = $this->intOrNull($confirmed['delivery'] ?? null);
        $confirmedTotal = $this->intOrNull($confirmed['total'] ?? null);

        if ($confirmedPromo !== $command->promoCode) {
            return true;
        }
        if ($confirmedDelivery !== $command->deliveryMethod) {
            return true;
        }
        if ($confirmedSubtotal !== $currentQuote['subtotal']) {
            return true;
        }
        if ($confirmedDiscount !== $currentQuote['discount']) {
            return true;
        }
        if ($confirmedDeliveryFee !== $currentQuote['delivery']) {
            return true;
        }
        if ($confirmedTotal !== $currentQuote['total']) {
            return true;
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        return (string) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (null === $value || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
