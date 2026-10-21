<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

/**
 * QRY-002 — one slide of the banner read model. `id` and `createdAt` are only filled for a requester
 * that may manage the banner (QRY-004): a public visitor gets the reference and its position, nothing
 * that would let them address a slide in a write.
 */
final readonly class BannerImageView
{
    public function __construct(
        public ?string $id,
        public string $path,
        public int $sortOrder,
        public ?string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'sortOrder' => $this->sortOrder,
            'createdAt' => $this->createdAt,
        ];
    }
}
