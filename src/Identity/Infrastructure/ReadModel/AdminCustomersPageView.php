<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

/**
 * R15 — one page of the dashboard user list plus the pagination meta the frontend needs.
 */
final readonly class AdminCustomersPageView
{
    /**
     * @param list<AdminCustomerView> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $limit,
        public int $total,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (AdminCustomerView $item): array => $item->toArray(), $this->items),
            'page' => $this->page,
            'limit' => $this->limit,
            'total' => $this->total,
        ];
    }
}
