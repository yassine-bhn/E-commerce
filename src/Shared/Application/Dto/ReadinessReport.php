<?php

declare(strict_types=1);

namespace App\Shared\Application\Dto;

final readonly class ReadinessReport
{
    /**
     * @param array<string, string> $checks probe name => "ok"|"failed"
     */
    public function __construct(
        public string $status,
        public array $checks,
    ) {
    }

    public function httpStatus(): int
    {
        return 'unavailable' === $this->status ? 503 : 200;
    }

    /**
     * @return array{status: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'checks' => $this->checks,
        ];
    }
}
