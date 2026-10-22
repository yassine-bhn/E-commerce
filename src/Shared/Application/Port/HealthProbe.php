<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * One readiness dimension (INF-001 database, migrations, search).
 * Critical probes flip readiness to 503; non-critical probes only degrade it.
 */
interface HealthProbe
{
    public function name(): string;

    public function isCritical(): bool;

    public function check(): bool;
}
