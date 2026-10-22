<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Shared\Application\Pagination\PageRequest;

final readonly class ListCustomerAddresses
{
    public const int DEFAULT_SIZE = 20;
    public const int MAX_SIZE = 100;

    public function __construct(
        public string $customerId,
        public PageRequest $page = new PageRequest(1, self::DEFAULT_SIZE, self::MAX_SIZE),
        public ?string $q = null,
    ) {
    }
}
