<?php

declare(strict_types=1);

namespace App\Shared\Application\Command;

/**
 * I-16 / O-19 — replaces the platform parameters an operator owns: contact channels, the
 * pending-order window and the payment method allow-list checkout accepts.
 */
final readonly class UpdatePlatformSettings
{
    /** @param list<string> $paymentMethods */
    public function __construct(
        public string $contactPhone,
        public string $contactWhatsapp,
        public int $autoCancelMinutes,
        public array $paymentMethods,
    ) {
    }
}
