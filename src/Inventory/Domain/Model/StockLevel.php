<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Model;

/**
 * spec-run-15 WP4 (R14) — the authoritative stock balance of one product.
 *
 * `on_hand` is what physically exists, `reserved` is what active orders hold, and `available()`
 * is what can still be sold. The three CHECK constraints of `inventory_stock` mirror these rules;
 * the value object applies the same ones before a caller composes a write, so an unsafe backfill
 * row is reported instead of inserted.
 */
final readonly class StockLevel
{
    public function __construct(
        public int $onHand,
        public int $reserved = 0,
    ) {
        if ($onHand < 0) {
            throw new \InvalidArgumentException('on_hand must be non-negative.');
        }
        if ($reserved < 0) {
            throw new \InvalidArgumentException('reserved must be non-negative.');
        }
        if ($reserved > $onHand) {
            throw new \InvalidArgumentException('reserved cannot exceed on_hand.');
        }
    }

    public function available(): int
    {
        return $this->onHand - $this->reserved;
    }
}
