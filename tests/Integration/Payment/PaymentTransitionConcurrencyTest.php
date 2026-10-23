<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Payment\Domain\Model\PaymentStatus;
use App\Payment\Domain\Model\PaymentTransition;
use App\Payment\Domain\Model\PaymentVersionConflictException;
use App\Payment\Infrastructure\Persistence\DbalPaymentInitiationAdapter;
use App\Payment\Infrastructure\Persistence\DbalPaymentLedger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The guard of `DbalPaymentLedger` cannot be proven by a functional test: two requests in one
 * process never overlap. This test uses two real backends, the same way the stock race test does.
 */
final class PaymentTransitionConcurrencyTest extends KernelTestCase
{
    public function testTwoConfirmationsOfTheSameVersionProduceOneTransition(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1 FROM payment LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('PostgreSQL schema not migrated (run doctrine:migrations:migrate).');
        }

        $orderId = Uuid::v7()->toRfc4122();
        $actorId = '01929312-7000-7000-8000-0000000000aa';

        (new DbalPaymentInitiationAdapter($connection))->open($orderId, 'Carte bancaire', 5000, 'TND', $actorId);
        $paymentId = (string) $connection->fetchOne('SELECT id FROM payment WHERE order_id = :order', ['order' => $orderId]);

        $ledger = new DbalPaymentLedger($connection);
        $rival = $this->rivalConnection($connection);
        $rivalLedger = new DbalPaymentLedger($rival);

        try {
            $connection->beginTransaction();
            try {
                $ledger->record($this->confirmation($paymentId, $actorId));

                // TDD-005 — the barrier is the server's own row lock: while the winner's
                // transaction is open, the rival's guarded UPDATE cannot complete.
                $rival->executeStatement("SET lock_timeout = '250ms'");
                $rival->beginTransaction();
                try {
                    $rivalLedger->record($this->confirmation($paymentId, $actorId));
                    self::fail('the rival confirmation must not proceed while the payment row is locked');
                } catch (DriverException $e) {
                    self::assertSame('55P03', $e->getSQLState(), $e->getMessage());
                } finally {
                    if ($rival->isTransactionActive()) {
                        $rival->rollBack();
                    }
                }

                $connection->commit();
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $e;
            }

            // The winner committed: the same rival, now unblocked, is refused by the version
            // guard instead of appending a second transition for the same movement.
            $rival->beginTransaction();
            try {
                $rivalLedger->record($this->confirmation($paymentId, $actorId));
                self::fail('the payment already moved to authorized at version 2');
            } catch (PaymentVersionConflictException $e) {
                // CR-001 — the loser is told what the row holds, not what it expected.
                self::assertStringContainsString('actual version is 2', $e->getMessage());
                $rival->rollBack();
            }

            $payment = $connection->fetchAssociative(
                'SELECT status, version FROM payment WHERE id = :id',
                ['id' => $paymentId],
            );
            self::assertIsArray($payment);
            self::assertSame('authorized', $payment['status']);
            self::assertSame(2, (int) $payment['version']);

            $ledgerRows = $connection->fetchAllAssociative(
                'SELECT from_status, to_status, version FROM payment_ledger WHERE payment_id = :id ORDER BY version',
                ['id' => $paymentId],
            );
            self::assertCount(2, $ledgerRows, 'one opening row and exactly one winning transition');
            self::assertNull($ledgerRows[0]['from_status']);
            self::assertSame('pending', $ledgerRows[0]['to_status']);
            self::assertSame('pending', $ledgerRows[1]['from_status']);
            self::assertSame('authorized', $ledgerRows[1]['to_status']);
            self::assertSame(2, (int) $ledgerRows[1]['version']);
        } finally {
            $rival->close();
            $connection->executeStatement('DELETE FROM payment_ledger WHERE payment_id = :id', ['id' => $paymentId]);
            $connection->executeStatement('DELETE FROM payment WHERE id = :id', ['id' => $paymentId]);
        }
    }

    private function confirmation(string $paymentId, string $actorId): PaymentTransition
    {
        return new PaymentTransition(
            $paymentId,
            1,
            PaymentStatus::PENDING,
            PaymentStatus::AUTHORIZED,
            5000,
            $actorId,
            'Offline receipt confirmed',
        );
    }

    /**
     * A second DBAL connection is a second backend: same database, own transaction and row locks.
     */
    private function rivalConnection(Connection $connection): Connection
    {
        /** @var array<string, mixed> $params */
        $params = $connection->getParams();
        unset($params['url'], $params['dbname_suffix']);
        $params['dbname'] = (string) $connection->fetchOne('SELECT current_database()');

        return DriverManager::getConnection($params);
    }
}
