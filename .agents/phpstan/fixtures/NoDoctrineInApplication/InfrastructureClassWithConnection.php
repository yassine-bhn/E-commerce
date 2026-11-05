<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final class InfrastructureClassWithConnection
{
    public function __construct(private Connection $connection)
    {
    }
}
