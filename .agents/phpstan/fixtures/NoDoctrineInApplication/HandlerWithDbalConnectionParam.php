<?php

declare(strict_types=1);

namespace App\Cart\Application\CommandHandler;

use Doctrine\DBAL\Connection;

final class HandlerWithDbalConnectionParam
{
    public function __construct(private Connection $connection)
    {
    }
}
