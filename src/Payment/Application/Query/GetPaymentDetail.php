<?php

declare(strict_types=1);

namespace App\Payment\Application\Query;

/**
 * QRY-004 — the requester travels with the query; the handler filters by ownership
 * unless the requester is an admin, so a controller cannot forget the check.
 */
final readonly class GetPaymentDetail
{
    public function __construct(
        public string $paymentId,
        public string $requesterId,
        public bool $isAdmin,
    ) {
    }
}
