<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Model;

enum OrderSource: string
{
    case ONLINE = 'ONLINE';
    case PHONE = 'PHONE';
    case WHATSAPP = 'WHATSAPP';
}
