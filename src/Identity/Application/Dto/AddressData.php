<?php

declare(strict_types=1);

namespace App\Identity\Application\Dto;

final readonly class AddressData
{
    public function __construct(
        public string $id,
        public string $customerId,
        public string $name,
        public string $phone,
        public string $street,
        public string $city,
        public string $governorate,
        public string $zip,
    ) {
    }
}
