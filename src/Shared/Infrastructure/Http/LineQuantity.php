<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * ERR-001 — a line quantity is validated at the edge, never repaired. Coercing `0` or `-2` to
 * `1` would bypass the positive-quantity rule the quote service enforces and turn a rejected
 * request into a silent purchase.
 */
final class LineQuantity
{
    /**
     * Returns the quantity as a positive integer, or null when the payload is not one.
     *
     * @param array<string, mixed> $line
     */
    public static function of(array $line): ?int
    {
        $value = $line['quantity'] ?? $line['qty'] ?? null;

        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;

            return $parsed >= 1 ? $parsed : null;
        }

        return null;
    }
}
