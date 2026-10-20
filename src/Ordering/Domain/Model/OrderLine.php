<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

use App\Shared\Domain\Money;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ordering_order_lines')]
class OrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(name: 'product_slug', length: 128)]
    private string $productSlug;

    #[ORM\Column(name: 'product_name', length: 255)]
    private string $productName;

    #[ORM\Column(name: 'variant_label', length: 128, nullable: true)]
    private ?string $variantLabel;

    #[ORM\Column(name: 'unit_price_minor', type: 'integer')]
    private int $unitPriceMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    private function __construct()
    {
    }

    public static function snapshot(
        string $id,
        string $productSlug,
        string $productName,
        ?string $variantLabel,
        Money $unitPrice,
        int $quantity,
    ): self {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1');
        }

        $line = new self();
        $line->id = $id;
        $line->productSlug = $productSlug;
        $line->productName = $productName;
        $line->variantLabel = $variantLabel;
        $line->unitPriceMinor = $unitPrice->minorUnits();
        $line->currency = $unitPrice->currency()->value;
        $line->quantity = $quantity;

        return $line;
    }

    public function attachTo(Order $order): void
    {
        $this->order = $order;
    }

    public function owningOrder(): Order
    {
        return $this->order;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productSlug(): string
    {
        return $this->productSlug;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function productName(): string
    {
        return $this->productName;
    }

    public function variantLabel(): ?string
    {
        return $this->variantLabel;
    }

    public function unitPriceMinor(): int
    {
        return $this->unitPriceMinor;
    }

    public function currencyCode(): string
    {
        return $this->currency;
    }
}
