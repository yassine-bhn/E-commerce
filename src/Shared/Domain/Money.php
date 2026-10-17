<?php

declare(strict_types=1);

namespace App\Shared\Domain;

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
