<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Port\HealthProbe;
use Doctrine\Migrations\DependencyFactory;

/**
 * A missing metadata table counts as pending: serving traffic before the schema exists is not ready.
 */
final class MigrationsHealthProbe implements HealthProbe
{
    public function __construct(private readonly DependencyFactory $dependencyFactory)
    {
    }

    public function name(): string
    {
        return 'migrations';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function check(): bool
    {
        $executed = $this->dependencyFactory->getMetadataStorage()->getExecutedMigrations()->count();
        $available = $this->dependencyFactory->getMigrationRepository()->getMigrations()->count();

        return $executed >= $available;
    }
}
