<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Catalog → Inventory anti-corruption port (spec-run-15 R17).
 *
 * An admin save declares the product's *available* quantity, never the physical or reserved one:
 * the adapter derives `on_hand = available + reserved` so an existing reservation is preserved.
 * The caller owns the transaction; a product deletion removes the balance row together with the
 * view row.
 */
interface InventoryStockPort
{
    public function setAvailable(string $productSlug, int $available): void;

    public function remove(string $productSlug): void;
}
