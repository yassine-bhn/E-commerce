<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\Command\ClearCart;
use App\Cart\Application\Command\RemoveCartItem;
use App\Cart\Application\Command\UpdateCartItem;
use App\Cart\Domain\Model\Cart;
use App\Cart\Domain\Model\CartLineId;
use App\Cart\Domain\Repository\CartRepository;
use App\Shared\Application\Port\StorefrontCatalogPort;
use App\Shared\Application\Service\StorefrontQuoteService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

final class CartCommandHandler
{
    public function __construct(
        private CartRepository $carts,
        private StorefrontCatalogPort $catalog,
        private StorefrontQuoteService $quotes,
    ) {
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function handleAddCartItem(AddCartItem $command): void
    {
        if (null === $this->catalog->getBySlug($command->productSlug)) {
            throw new \InvalidArgumentException('Product not found');
        }

        $pricing = $this->quoteLine($command->productSlug, $command->variant, 1);
        $cart = $this->loadOrCreate($command->customerId);
        $cart->addItem(
            new CartLineId(Uuid::v7()->toRfc4122()),
            $command->productSlug,
            $command->variant,
            max(1, $command->quantity),
            $pricing['unitPriceMinor'],
            $pricing['currency'],
        );
        $this->carts->save($cart);
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function handleUpdateCartItem(UpdateCartItem $command): void
    {
        $cart = $this->requireCart($command->customerId);
        $line = $cart->findLine($command->itemId);
        if (null === $line) {
            throw new \InvalidArgumentException('Cart item not found');
        }

        if (null !== $command->variant) {
            $pricing = $this->quoteLine($line->productSlug(), $command->variant, 1);
            $cart->updateItemVariant($command->itemId, $command->variant, $pricing['unitPriceMinor']);
        }

        if (null !== $command->quantity) {
            $cart->updateItemQuantity($command->itemId, max(1, $command->quantity));
        }

        $this->carts->save($cart);
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function handleRemoveCartItem(RemoveCartItem $command): void
    {
        $cart = $this->requireCart($command->customerId);
        if (null === $cart->findLine($command->itemId)) {
            throw new \InvalidArgumentException('Cart item not found');
        }
        $cart->removeItem($command->itemId);
        $this->carts->save($cart);
    }

    #[AsMessageHandler(bus: 'command.bus')]
    public function handleClearCart(ClearCart $command): void
    {
        $cart = $this->carts->findByCustomerId($command->customerId);
        if (null === $cart) {
            return;
        }
        $cart->clear();
        $this->carts->save($cart);
    }

    private function loadOrCreate(string $customerId): Cart
    {
        $cart = $this->carts->findByCustomerId($customerId);
        if (null !== $cart) {
            return $cart;
        }

        return Cart::create($customerId);
    }

    private function requireCart(string $customerId): Cart
    {
        $cart = $this->carts->findByCustomerId($customerId);
        if (null === $cart) {
            throw new \InvalidArgumentException('Cart not found');
        }

        return $cart;
    }

    /**
     * @return array{unitPriceMinor: int, currency: string}
     */
    private function quoteLine(string $slug, ?string $variant, int $quantity): array
    {
        $result = $this->quotes->quote(
            [['slug' => $slug, 'variant' => $variant, 'quantity' => $quantity]],
            null,
            'home',
        );
        $priced = $result['pricedLines'][0];

        return [
            'unitPriceMinor' => $priced['unitPriceMinor'],
            'currency' => $result['subtotal']->currency()->value,
        ];
    }
}
