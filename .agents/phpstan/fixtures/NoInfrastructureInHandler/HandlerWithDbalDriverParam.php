<?php

declare(strict_types=1);

namespace App\Ordering\Application\CommandHandler;

use Doctrine\DBAL\Driver;

final class HandlerWithDbalDriverParam
{
    public function __construct(private Driver $driver)
    {
    }
}
