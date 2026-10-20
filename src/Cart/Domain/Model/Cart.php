<?php

declare(strict_types=1);

namespace App\Cart\Domain\Model;

use App\Cart\Domain\Event\CartCreated;
use App\Shared\Domain\AggregateRoot;
use Symfony\Component\Uid\Uuid;

final class Cart extends AggregateRoot
{
    /** @var list<CartLine> */
    private array $lines = [];

    private function __construct(
        private CartId $id,
        private string $customerId,
    ) {
    }

    public static function create(string $customerId = ''): self
    {
        $cart = new self(new CartId(Uuid::v7()->toRfc4122()), $customerId);
        $cart->record(new CartCreated($cart->id(), $cart->customerId()));

        return $cart;
    }

    /** @param list<CartLine> $lines */
    public static function reconstitute(string $id, string $customerId, array $lines): self
    {
        $cart = new self(new CartId($id), $customerId);
        $cart->lines = $lines;

        return $cart;
    }

    public function id(): string
    {
        return $this->id->toString();
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    /** @return list<CartLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function itemCount(): int
    {
        $count = 0;
        foreach ($this->lines as $line) {
            $count += $line->quantity();
        }

        return $count;
    }

    public function findLine(string $lineId): ?CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->id()->toString() === $lineId) {
                return $line;
            }
        }

        return null;
    }

    public function addItem(
        CartLineId $lineId,
        string $productSlug,
        ?string $variant,
        int $quantity,
        int $unitPriceMinor,
        string $currency,
    ): CartLine {
        foreach ($this->lines as $line) {
            if ($line->matchesProduct($productSlug, $variant)) {
                $line->changeQuantity($line->quantity() + $quantity);

                return $line;
            }
        }

        $line = CartLine::create($lineId, $productSlug, $variant, $quantity, $unitPriceMinor, $currency);
        $this->lines[] = $line;

        return $line;
    }

    public function updateItemQuantity(string $lineId, int $quantity): CartLine
    {
        $line = $this->requireLine($lineId);
        $line->changeQuantity($quantity);

        return $line;
    }

    public function updateItemVariant(string $lineId, ?string $variant, int $unitPriceMinor): CartLine
    {
        $line = $this->requireLine($lineId);
        $line->changeVariant($variant, $unitPriceMinor);

        return $line;
    }

    public function removeItem(string $lineId): void
    {
        $this->lines = array_values(array_filter(
            $this->lines,
            static fn (CartLine $line): bool => $line->id()->toString() !== $lineId,
        ));
    }

    public function clear(): void
    {
        $this->lines = [];
    }

    private function requireLine(string $lineId): CartLine
    {
        $line = $this->findLine($lineId);
        if (null === $line) {
            throw new \InvalidArgumentException(sprintf('Cart line not found: %s', $lineId));
        }

        return $line;
    }
}
