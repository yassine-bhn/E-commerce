<?php

declare(strict_types=1);

namespace App\Shared\Application\Service;

use App\Shared\Application\Dto\ReadinessReport;
use App\Shared\Application\Port\HealthProbe;

final class ReadinessService
{
    /**
     * @param iterable<HealthProbe> $probes
     */
    public function __construct(private readonly iterable $probes)
    {
    }

    public function report(): ReadinessReport
    {
        $checks = [];
        $unavailable = false;
        $degraded = false;

        foreach ($this->probes as $probe) {
            $healthy = $this->isHealthy($probe);
            $checks[$probe->name()] = $healthy ? 'ok' : 'failed';

            if ($healthy) {
                continue;
            }

            if ($probe->isCritical()) {
                $unavailable = true;
            } else {
                $degraded = true;
            }
        }

        $status = match (true) {
            $unavailable => 'unavailable',
            $degraded => 'degraded',
            default => 'ok',
        };

        return new ReadinessReport($status, $checks);
    }

    private function isHealthy(HealthProbe $probe): bool
    {
        try {
            return $probe->check();
        } catch (\Throwable) {
            return false;
        }
    }
}
