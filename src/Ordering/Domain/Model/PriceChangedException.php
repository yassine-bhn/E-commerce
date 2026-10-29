<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

final class PriceChangedException extends \DomainException
{
    /**
     * @param array<string, mixed>       $currentQuote
     * @param list<array<string, mixed>> $changedLines
     */
    public function __construct(
        public readonly array $currentQuote,
        public readonly array $changedLines,
    ) {
        parent::__construct('price-changed');
    }
}
