<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductRegistered;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_products')]
class Product extends AggregateRoot
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(name: 'price_minor', type: 'integer')]
    private int $priceMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    private function __construct()
    {
    }

    public static function create(ProductId $id, string $name, Money $price): self
    {
        $product = new self();
        $product->id = $id->toString();
        $product->name = $name;
        $product->priceMinor = $price->minorUnits();
        $product->currency = $price->currency()->value;

        $product->record(new ProductRegistered(
            Uuid::v7()->toRfc4122(),
            $product->id,
            $product->name,
            $product->priceMinor,
            $product->currency,
            new \DateTimeImmutable(),
        ));

        return $product;
    }

    public function id(): ProductId
    {
        return new ProductId($this->id);
    }

    public function changePrice(Money $newPrice): void
    {
        $this->priceMinor = $newPrice->minorUnits();
        $this->currency = $newPrice->currency()->value;

        $this->record(new ProductPriceChanged(
            Uuid::v7()->toRfc4122(),
            $this->id,
            $this->priceMinor,
            $this->currency,
            new \DateTimeImmutable(),
        ));
    }

    public function price(): Money
    {
        return Money::ofMinor($this->priceMinor, \App\Shared\Domain\Currency::from($this->currency));
    }
}
