<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

interface CartPricerPort
{
}

interface CartLoaderPort
{
}

interface CartStorePort
{
}

interface CartClockPort
{
}

interface CartMailerPort
{
}

interface CartAuditorPort
{
}

final class SixDependenciesHandler
{
    public function __construct(
        private CartPricerPort $pricer,
        private CartLoaderPort $loader,
        private CartStorePort $store,
        private CartClockPort $clock,
        private CartMailerPort $mailer,
        private CartAuditorPort $auditor,
    ) {
    }
}
