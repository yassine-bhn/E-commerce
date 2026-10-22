<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Health;

use App\Shared\Application\Port\HealthProbe;
use App\Shared\Application\Service\ReadinessService;
use PHPUnit\Framework\TestCase;

final class ReadinessServiceTest extends TestCase
{
    public function testAllProbesHealthyReportsOk(): void
    {
        $service = new ReadinessService([
            $this->probe('database', true, true),
            $this->probe('migrations', true, true),
            $this->probe('search', false, true),
        ]);

        $report = $service->report();

        self::assertSame('ok', $report->status);
        self::assertSame(200, $report->httpStatus());
        self::assertSame(['database' => 'ok', 'migrations' => 'ok', 'search' => 'ok'], $report->checks);
    }

    public function testCriticalProbeFailureMakesReadinessUnavailable(): void
    {
        $service = new ReadinessService([
            $this->probe('database', true, false),
            $this->probe('migrations', true, true),
            $this->probe('search', false, true),
        ]);

        $report = $service->report();

        self::assertSame('unavailable', $report->status);
        self::assertSame(503, $report->httpStatus());
        self::assertSame('failed', $report->checks['database']);
        self::assertSame('ok', $report->checks['migrations']);
        self::assertSame('ok', $report->checks['search']);
    }

    public function testPendingMigrationMakesReadinessUnavailable(): void
    {
        $service = new ReadinessService([
            $this->probe('database', true, true),
            $this->probe('migrations', true, false),
        ]);

        $report = $service->report();

        self::assertSame('unavailable', $report->status);
        self::assertSame(503, $report->httpStatus());
        self::assertSame('failed', $report->checks['migrations']);
    }

    public function testNonCriticalProbeFailureOnlyDegrades(): void
    {
        $service = new ReadinessService([
            $this->probe('database', true, true),
            $this->probe('migrations', true, true),
            $this->probe('search', false, false),
        ]);

        $report = $service->report();

        self::assertSame('degraded', $report->status);
        self::assertSame(200, $report->httpStatus());
        self::assertSame('failed', $report->checks['search']);
    }

    public function testProbeThrowingIsCountedAsAFailure(): void
    {
        $throwing = new class implements HealthProbe {
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
                throw new \RuntimeException('connection refused');
            }
        };

        $report = (new ReadinessService([$throwing]))->report();

        self::assertSame('unavailable', $report->status);
        self::assertSame('failed', $report->checks['database']);
    }

    private function probe(string $name, bool $critical, bool $healthy): HealthProbe
    {
        return new class($name, $critical, $healthy) implements HealthProbe {
            public function __construct(
                private readonly string $name,
                private readonly bool $critical,
                private readonly bool $healthy,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function isCritical(): bool
            {
                return $this->critical;
            }

            public function check(): bool
            {
                return $this->healthy;
            }
        };
    }
}
