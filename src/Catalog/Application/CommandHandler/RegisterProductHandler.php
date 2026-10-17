<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\RegisterProduct;
use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\ProductId;
use App\Catalog\Domain\Repository\ProductRepository;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Domain\Outbox\OutboxPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RegisterProductHandler
{
    public function __construct(
        private ProductRepository $products,
        private OutboxPort $outbox,
    ) {
    }

    public function __invoke(RegisterProduct $command): void
    {
        $product = Product::create(
            new ProductId($command->productId),
            $command->name,
            Money::ofMinor($command->priceMinor, Currency::from($command->currency)),
        );

        $this->products->save($product);
        $this->outbox->append(Product::class, $command->productId, $product->releaseEvents());
    }
}
