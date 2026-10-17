<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class MessengerQuery
{
    public function __construct(private MessageBusInterface $queryBus)
    {
    }

    public function dispatch(object $query): mixed
    {
        $envelope = $this->queryBus->dispatch($query);
        $handled = $envelope->last(HandledStamp::class);
        if (null === $handled) {
            throw new \RuntimeException('Query was not handled');
        }

        return $handled->getResult();
    }
}
