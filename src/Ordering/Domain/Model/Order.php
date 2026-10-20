<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderStatusChanged;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'ordering_orders')]
class Order extends AggregateRoot
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'customer_id', type: 'guid')]
    private string $customerId;

    #[ORM\Column(type: 'string', enumType: OrderStatus::class)]
    private OrderStatus $status;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'order_number', length: 32)]
    private string $orderNumber;

    #[ORM\Column(name: 'order_source', type: 'string', enumType: OrderSource::class)]
    private OrderSource $orderSource;

    #[ORM\Column(name: 'subtotal_minor', type: 'integer')]
    private int $subtotalMinor;

    #[ORM\Column(name: 'discount_minor', type: 'integer')]
    private int $discountMinor;

    #[ORM\Column(name: 'delivery_minor', type: 'integer')]
    private int $deliveryMinor;

    #[ORM\Column(name: 'total_minor', type: 'integer')]
    private int $totalMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'delivery_method', length: 16)]
    private string $deliveryMethod;

    #[ORM\Column(name: 'payment_method', length: 64)]
    private string $paymentMethod;

    #[ORM\Column(name: 'promo_code', length: 32, nullable: true)]
    private ?string $promoCode;

    #[ORM\Column(name: 'placed_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $placedAt;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    private function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    /**
     * @param list<OrderLine> $lines
     */
    public static function placeOnline(
        OrderId $id,
        string $customerId,
        string $orderNumber,
        Money $subtotal,
        Money $discount,
        Money $delivery,
        Money $total,
        string $deliveryMethod,
        string $paymentMethod,
        ?string $promoCode,
        array $lines,
        \DateTimeImmutable $placedAt,
    ): self {
        if ([] === $lines) {
            throw new \InvalidArgumentException('Order must have at least one line');
        }

        $order = new self();
        $order->id = $id->toString();
        $order->customerId = $customerId;
        $order->status = OrderStatus::PENDING;
        $order->version = 1;
        $order->orderNumber = $orderNumber;
        $order->orderSource = OrderSource::ONLINE;
        $order->subtotalMinor = $subtotal->minorUnits();
        $order->discountMinor = $discount->minorUnits();
        $order->deliveryMinor = $delivery->minorUnits();
        $order->totalMinor = $total->minorUnits();
        $order->currency = $total->currency()->value;
        $order->deliveryMethod = $deliveryMethod;
        $order->paymentMethod = $paymentMethod;
        $order->promoCode = $promoCode;
        $order->placedAt = $placedAt;

        foreach ($lines as $line) {
            $line->attachTo($order);
            $order->lines->add($line);
        }

        $order->record(new OrderPlaced(
            Uuid::v7()->toRfc4122(),
            $order->id,
            $order->customerId,
            $order->orderNumber,
            $order->totalMinor,
            $order->currency,
            $order->orderSource->value,
            $placedAt,
        ));

        return $order;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function source(): OrderSource
    {
        return $this->orderSource;
    }

    /** @return list<OrderLine> */
    public function lines(): array
    {
        return $this->lines->getValues();
    }

    public function id(): OrderId
    {
        return new OrderId($this->id);
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function placedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function deliveryMethod(): string
    {
        return $this->deliveryMethod;
    }

    public function paymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function promoCode(): ?string
    {
        return $this->promoCode;
    }

    public function total(): Money
    {
        return Money::ofMinor($this->totalMinor, Currency::from($this->currency));
    }

    public function subtotal(): Money
    {
        return Money::ofMinor($this->subtotalMinor, Currency::from($this->currency));
    }

    public function discount(): Money
    {
        return Money::ofMinor($this->discountMinor, Currency::from($this->currency));
    }

    public function delivery(): Money
    {
        return Money::ofMinor($this->deliveryMinor, Currency::from($this->currency));
    }

    public function transitionTo(OrderStatus $target, int $expectedVersion): void
    {
        if ($this->version !== $expectedVersion) {
            throw OptimisticLockException::versionMismatch($this->id, $expectedVersion, $this->version);
        }

        $fromStatus = $this->status;
        $fromVersion = $this->version;
        $fromStatus->ensureCanTransitionTo($target);
        $this->status = $target;
        ++$this->version;

        $this->record(new OrderStatusChanged(
            Uuid::v7()->toRfc4122(),
            $this->id,
            $fromStatus->value,
            $target->value,
            $fromVersion,
            $this->version,
            new \DateTimeImmutable(),
        ));
    }
}
