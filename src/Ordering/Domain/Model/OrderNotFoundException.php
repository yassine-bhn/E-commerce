<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

final class OrderNotFoundException extends \DomainException
{
    public function __construct(string $orderId)
    {
        parent::__construct(sprintf('Order with ID "%s" not found.', $orderId));
    }
}
