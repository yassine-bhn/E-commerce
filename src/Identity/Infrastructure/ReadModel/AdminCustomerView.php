<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\ReadModel;

/**
 * R15 / QRY-002 — one dashboard user row. Deliberately only the fields the dashboard is allowed to
 * see: no password hash, no OAuth id, no ban internals (ban_reason/banned_by/banned_at).
 */
final readonly class AdminCustomerView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $phone,
        public string $role,
        public string $status,
        public int $orderCount,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'status' => $this->status,
            'orderCount' => $this->orderCount,
        ];
    }
}
