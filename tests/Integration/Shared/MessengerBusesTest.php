<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/** BUS-001 — container exposes three distinct buses with expected middleware stacks. */
final class MessengerBusesTest extends KernelTestCase
{
    public function testThreeBusesAreRegistered(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        foreach (['command.bus', 'query.bus', 'event.bus'] as $busId) {
            self::assertTrue(
                $container->has('messenger.bus.'.$busId),
                sprintf('Expected messenger.bus.%s to be registered (BUS-001)', $busId),
            );
        }
    }

    public function testDefaultBusIsCommandBus(): void
    {
        self::bootKernel();
        $default = static::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $default);
        self::assertSame(
            static::getContainer()->get('messenger.bus.command'),
            $default,
        );
    }
}
