<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

final class NonServiceWithSixDependencies
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
