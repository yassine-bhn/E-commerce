<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\Dto\NewCustomer;

/**
 * DIP-003 — registration: the case-insensitive existence pre-check and the insert. The insert
 * answers a lost race with EmailAlreadyUsedException (spec-run-12 R7/R10).
 */
interface CustomerRegistrar
{
    public function emailExists(string $email): bool;

    public function register(NewCustomer $customer): void;
}
