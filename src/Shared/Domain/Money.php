<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Integer money value object. The amount is expressed in the currency's smallest unit
 * (millimes for TND, cents for EUR/USD/GBP), never as a float.
 */
final readonly class Money
{
    public function __construct(
        private int $minorUnits,
        private Currency $currency,
    ) {
        if ($minorUnits < 0) {
            throw new \InvalidArgumentException('Money minor units cannot be negative');
        }
    }

    public static function ofMinor(int $minorUnits, Currency $currency): self
    {
        return new self($minorUnits, $currency);
    }

    /**
     * Parses a decimal amount (`15.3`, `153894.32`, `680`) exactly into the currency's smallest
     * unit using string arithmetic only. Rejects malformed/negative input and more fractional
     * digits than the currency declares (three for TND, two for EUR/USD/GBP).
     */
    public static function fromDecimalString(string $decimal, Currency $currency): self
    {
        if (str_starts_with($decimal, '-')) {
            throw new \InvalidArgumentException('Money cannot be negative');
        }

        if (1 !== preg_match('/^(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $decimal, $matches)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid decimal amount', $decimal));
        }

        $scale = $currency->fractionalDigits();
        $fraction = $matches['fraction'] ?? '';
        if (strlen($fraction) > $scale) {
            throw new \InvalidArgumentException(sprintf('"%s" has more than %d fractional digits for %s', $decimal, $scale, $currency->value));
        }

        $digits = ltrim($matches['whole'].str_pad($fraction, $scale, '0'), '0');
        if ('' === $digits) {
            return new self(0, $currency);
        }

        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new \InvalidArgumentException(sprintf('"%s" is too large to represent', $decimal));
        }

        return new self((int) $digits, $currency);
    }

    /**
     * The canonical decimal amount, dropping unnecessary trailing zeros:
     * `15300` TND → `15.3`, `680000` TND → `680`, `153894320` TND → `153894.32`.
     */
    public function toDecimalString(): string
    {
        $scale = $this->currency->fractionalDigits();
        $factor = 10 ** $scale;
        $whole = intdiv($this->minorUnits, $factor);
        $fraction = $this->minorUnits % $factor;

        if (0 === $fraction) {
            return (string) $whole;
        }

        $digits = rtrim(str_pad((string) $fraction, $scale, '0', STR_PAD_LEFT), '0');

        return $whole.'.'.$digits;
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->minorUnits > $this->minorUnits) {
            throw new \InvalidArgumentException('Money subtraction would be negative');
        }

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException('Factor cannot be negative');
        }

        return new self($this->minorUnits * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency === $other->currency;
    }

    /** Half-up rounding; basis points are 10000 = 100%. */
    public function basisPointDiscount(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new \InvalidArgumentException('Basis points must be between 0 and 10000');
        }

        $discountMinor = intdiv($this->minorUnits * $basisPoints + 5000, 10000);

        return new self($discountMinor, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Currency mismatch');
        }
    }
}
