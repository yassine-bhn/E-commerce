<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\Dto\CustomerProfileData;

/**
 * DIP-003 — the profile upsert. A collision with another account's address leaves as
 * EmailAlreadyUsedException, the same conflict registration reports (R10).
 */
interface CustomerProfileStore
{
    public function upsert(CustomerProfileData $profile): void;
}
