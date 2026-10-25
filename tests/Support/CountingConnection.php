<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * A transparent DBAL spy: it delegates every read, write and transaction call to the connection it
 * wraps, so instrumented code really runs against the test database, and records which statements it
 * was asked to run.
 *
 * Transactions are delegated too, which is what makes it usable at the HTTP edge: a service that
 * opens a transaction on the spy and writes through it shares the wrapped connection's transaction,
 * so a request's statements are counted without changing what the request does.
 */
final class CountingConnection extends Connection
{
    /** @var array<string, int> */
    public array $calls = [];

    private Connection $inner;

    public static function wrap(Connection $inner): self
    {
        $spy = new self($inner->getParams(), $inner->getDriver());
        $spy->inner = $inner;

        return $spy;
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $this->record('fetchAssociative');

        return $this->inner->fetchAssociative($query, $params, $types);
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->record('fetchAllAssociative');

        return $this->inner->fetchAllAssociative($query, $params, $types);
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        $this->record('fetchOne');

        return $this->inner->fetchOne($query, $params, $types);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $this->record('executeStatement');

        return $this->inner->executeStatement($sql, $params, $types);
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        $this->record('insert');

        return $this->inner->insert($table, $data, $types);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function isTransactionActive(): bool
    {
        return $this->inner->isTransactionActive();
    }

    private function record(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}
