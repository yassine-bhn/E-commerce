<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Http;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AllowedDepsController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private RateLimiterFactoryInterface $limiter,
        private Security $security,
        private string $environment,
    ) {
    }
}
