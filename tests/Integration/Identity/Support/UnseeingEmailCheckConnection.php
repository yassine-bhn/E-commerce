<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Support;

use Doctrine\DBAL\Connection;

/**
 * A connection whose e-mail pre-check cannot see the row a concurrent registration just wrote.
 *
 * The raced path of `RegisterCustomerHandler` is the only one that reaches the database's unique
 * index, and it cannot be reproduced by inserting twice through the handler: the second call would
 * be refused by the SELECT before the INSERT. This double therefore answers "no such address" to
 * that one query and delegates everything else, so the INSERT really runs and really conflicts.
 */
final class UnseeingEmailCheckConnection extends Connection
{
    private Connection $inner;

    public static function wrapping(Connection $inner): self
    {
        $double = new self($inner->getParams(), $inner->getDriver());
        $double->inner = $inner;

        return $double;
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if (str_contains($query, 'FROM customer_profile WHERE lower(email)')) {
            return false;
        }

        return $this->inner->fetchOne($query, $params, $types);
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        return $this->inner->fetchAssociative($query, $params, $types);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return $this->inner->executeStatement($sql, $params, $types);
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
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
}
