<?php

declare(strict_types=1);

namespace App\Promotions\Domain\Model;

final class PromoCodeAlreadyExistsException extends \DomainException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('This promo code already exists: %s', $code));
    }
}
