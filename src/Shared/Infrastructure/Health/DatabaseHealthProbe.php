<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Port\HealthProbe;
use Doctrine\DBAL\Connection;

final class DatabaseHealthProbe implements HealthProbe
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function check(): bool
    {
        return 1 === (int) $this->connection->fetchOne('SELECT 1');
    }
}
