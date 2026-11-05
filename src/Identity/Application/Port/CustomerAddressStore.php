<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Identity\Application\Dto\AddressData;

/**
 * DIP-003 — saved-address writes; delete refuses cross-account ids in the SQL itself.
 */
interface CustomerAddressStore
{
    public function save(AddressData $address): void;

    public function delete(string $id, string $customerId): void;
}
