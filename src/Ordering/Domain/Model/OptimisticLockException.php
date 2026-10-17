<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

final class OptimisticLockException extends \RuntimeException
{
    public static function versionMismatch(string $orderId, int $expectedVersion, int $actualVersion): self
    {
        return new self(sprintf(
            'Conflict: order version mismatch for order "%s". Expected version %d, but actual version is %d.',
            $orderId,
            $expectedVersion,
            $actualVersion,
        ));
    }
}
