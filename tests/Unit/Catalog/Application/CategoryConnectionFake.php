<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\PgSQL\Driver as PgSqlDriver;
use PHPUnit\Framework\Assert;

/**
 * Inline fake (TDD-008) of the DBAL connection a category command handler talks to:
 * it records every statement and replays the rows the handler would read back, so the
 * write-side rules can be tested without a database.
 */
final class CategoryConnectionFake extends Connection
{
    /** @var list<array{sql: string, params: array<array-key, mixed>}> */
    public array $writes = [];

    /** @var list<mixed> */
    public array $scalars = [];

    /** @var list<array<string, mixed>|false> */
    public array $rows = [];

    /** @var list<list<array<string, mixed>>> */
    public array $rowLists = [];

    public ?\Throwable $failNextWrite = null;

    public int $affectedRows = 1;

    private bool $forbidWrites = false;

    /**
     * Chains to the parent so the fake stays a real Doctrine\DBAL\Connection, but every read
     * and write is intercepted below, so no driver is ever asked to connect.
     *
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [], ?Driver $driver = null, ?Configuration $config = null)
    {
        parent::__construct($params, $driver ?? new PgSqlDriver(), $config);
    }

    /**
     * Any statement issued after this call fails the test: a refused write must leave
     * the table untouched.
     */
    public function expectsNoWrite(): void
    {
        $this->forbidWrites = true;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        if ($this->forbidWrites) {
            Assert::fail('a refused category write must not touch the database: '.$sql);
        }

        if (null !== $this->failNextWrite) {
            $failure = $this->failNextWrite;
            $this->failNextWrite = null;

            throw $failure;
        }

        $this->writes[] = ['sql' => $sql, 'params' => $params];

        return $this->affectedRows;
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        return array_shift($this->scalars);
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        $row = array_shift($this->rows);

        return $row ?? false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        return array_shift($this->rowLists) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function lastWrite(): ?array
    {
        return [] === $this->writes ? null : $this->writes[count($this->writes) - 1];
    }
}
