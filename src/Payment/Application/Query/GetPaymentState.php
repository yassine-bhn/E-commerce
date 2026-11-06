<?php

declare(strict_types=1);

namespace App\Payment\Application\Query;

final readonly class GetPaymentState
{
    public function __construct(public string $paymentId)
    {
    }
}
