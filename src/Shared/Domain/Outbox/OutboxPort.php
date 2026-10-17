<?php

declare(strict_types=1);

namespace App\Shared\Domain\Outbox;

interface OutboxPort
{
    /** @param list<object> $domainEvents */
    public function append(string $aggregateType, string $aggregateId, array $domainEvents): void;
}
