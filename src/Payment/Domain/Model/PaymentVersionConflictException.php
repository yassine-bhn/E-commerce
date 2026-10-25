<?php

declare(strict_types=1);

namespace App\Payment\Domain\Model;

final class PaymentVersionConflictException extends \RuntimeException
{
    public static function versionMismatch(string $paymentId, int $expectedVersion, int $actualVersion): self
    {
        return new self(sprintf(
            'Conflict: payment version mismatch for payment "%s". Expected version %d, but actual version is %d.',
            $paymentId,
            $expectedVersion,
            $actualVersion,
        ));
    }
}
