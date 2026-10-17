<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\Saga\CheckoutSaga;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CheckoutSagaTest extends TestCase
{
    public function testStartCheckoutDispatchesPlaceOrder(): void
    {
        $command = new PlaceOrder(
            'o1',
            'c1',
            'key-1',
            'ZG-TEST',
            [['slug' => 'lavabo', 'quantity' => 1]],
            'home',
            'Carte bancaire',
            null,
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with($command)
            ->willReturn(new Envelope($command));

        (new CheckoutSaga($bus))->startCheckout($command);
    }
}
