<?php

declare(strict_types=1);

namespace App\Promotions\Domain\Model;

final class PromoCodeNotFoundException extends \DomainException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Promo code not found: %s', $code));
    }
}
