<?php

declare(strict_types=1);

namespace App\Identity\Application\Dto;

final readonly class QuoteRequestData
{
    /**
     * @param list<string> $subcategories
     */
    public function __construct(
        public string $quoteId,
        public ?string $userId,
        public string $fullName,
        public string $email,
        public string $phone,
        public ?string $city,
        public string $category,
        public array $subcategories,
        public string $message,
    ) {
    }
}
