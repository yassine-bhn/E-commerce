<?php

declare(strict_types=1);

namespace App\Shared\Application\Query;

final readonly class GetWhatsAppContact
{
    public function __construct(public ?string $contextualMessage = null)
    {
    }
}
