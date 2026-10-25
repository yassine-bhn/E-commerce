<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Command\DecideCatalogSubmission;
use App\Catalog\Application\CommandHandler\DecideCatalogSubmissionHandler;
use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Infrastructure\Audit\DbalCatalogAuditWriter;
use App\Tests\Integration\Catalog\Support\CatalogRow;
use App\Tests\Integration\Catalog\Support\ProbingCatalogAudit;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * spec-run-12 R5/R6 — R38's transaction proven across a boundary, not between two statements.
 *
 * The existing transaction test stays inside one connection: it fails the audit adapter and reads the
 * rollback through the same session that wrote it. That cannot tell a committed decision from one
 * that merely exists in a session's uncommitted work.
 *
 * R5 crosses the connection: while the handler is running inside its transaction, a second connection
 * — the reader the storefront and the queue would use — sees the pre-decision status and no audit
 * entry; after the commit it sees both. The probe sits in the audit writer, inside the handler's own
 * transaction, so it is that transaction being proven and not one the test opened around it.
 *
 * R6 crosses the process: tests/Support/doomed_decision_writer.php runs the real handler in its own
 * process, proves to itself that both writes exist, then SIGKILLs itself between the write and the
 * commit. PostgreSQL rolls the orphaned transaction back, so nothing of that decision survives.
 */
final class CatalogAuditProcessIsolationTest extends KernelTestCase
{
    private const string PRODUCT = 'it-process-isolation-product';
    private const string REVIEWER_ID = '01929312-0000-7000-8000-0000000000bb';

    private ?Connection $observer = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->removeRows(self::connection());
        CatalogRow::product(self::connection(), self::PRODUCT, 'PENDING_APPROVAL');
    }

    protected function tearDown(): void
    {
        $this->observer?->close();
        $this->observer = null;
        $this->removeRows(self::connection());

        parent::tearDown();
    }

    public function testASecondConnectionSeesTheDecisionOnlyOnceTheTransactionCommits(): void
    {
        $writer = self::connection();
        $observer = $this->observer();

        // Deliberately no transaction around the call: DBAL would nest the handler's as a savepoint,
        // and every assertion below would hold even if the handler opened none at all.
        $audit = new ProbingCatalogAudit(
            new DbalCatalogAuditWriter($writer),
            $observer,
            $writer,
            'product',
            'storefront_product_view',
            self::PRODUCT,
        );
        $handler = new DecideCatalogSubmissionHandler($writer, $audit);

        $handler(new DecideCatalogSubmission(
            CatalogSubmissionKind::Product,
            self::PRODUCT,
            CatalogDecision::Approve,
            self::REVIEWER_ID,
        ));

        self::assertCount(1, $audit->atWrite, 'the decision writes one audit entry');
        $atWrite = $audit->atWrite[0];

        self::assertSame(
            'PENDING_APPROVAL',
            $atWrite['reader'],
            'R5 — at the moment the audit row is written, the second connection still reads the status that was committed',
        );
        self::assertSame(0, $atWrite['readerRows'], 'R5 — an uncommitted audit entry is invisible to every other reader');

        // The same connection reads its own uncommitted work, which is what makes the assertion
        // above about isolation rather than about the write never happening.
        self::assertSame('APPROVED', $atWrite['writer'], 'the writer has already changed the row; only the commit is missing');
        self::assertSame(1, $atWrite['writerRows'], 'the writer already wrote the audit entry; only the commit is missing');

        self::assertSame('APPROVED', $this->approvalStatus($observer), 'R5 — after the commit the decision is what every reader sees');
        self::assertSame(1, $this->auditRows($observer), 'R5 — the audit entry crosses the boundary with it, in the same commit');
    }

    public function testAWriterKilledBetweenItsUpdateAndCommitLeavesNeitherTheDecisionNorAnAuditEntry(): void
    {
        $marker = sys_get_temp_dir().'/doomed-writer-'.bin2hex(random_bytes(6)).'.marker';
        $log = sys_get_temp_dir().'/doomed-writer-'.bin2hex(random_bytes(6)).'.log';

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3).'/tests/Support/doomed_decision_writer.php', self::PRODUCT, $marker],
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            ['APP_ENV' => 'test', 'APP_DEBUG' => '0'] + getenv(),
        );
        self::assertIsResource($process, 'the doomed writer process could not be started');

        try {
            $this->awaitMarker($process, $marker, $log);
            $status = $this->awaitExit($process, $log);

            self::assertTrue($status['signaled'], 'R6 — the writer has to die, not exit: '.$this->log($log));
            self::assertSame(9, $status['termsig'], 'R6 — a SIGKILL is what a killed worker leaves behind: '.$this->log($log));
            self::assertFileExists($marker, 'the writer reported its two writes before it died');
        } finally {
            $this->terminate($process);
            @unlink($marker);
            @unlink($log);
        }

        self::assertSame(
            'PENDING_APPROVAL',
            $this->approvalStatus(self::connection()),
            'R6 — the state UPDATE died with its transaction: the submission is still pending',
        );
        self::assertNull(
            self::connection()->fetchOne('SELECT reviewed_by FROM storefront_product_view WHERE slug = :slug', ['slug' => self::PRODUCT]),
            'R6 — the reviewer of a decision that never committed is not stored',
        );
        self::assertSame(0, $this->auditRows(self::connection()), 'R6 — the audit entry died with the transaction it belonged to');
    }

    /**
     * Waits for the marker the child writes after its two writes and before it kills itself, so the
     * parent never asserts on a process that has not written yet.
     *
     * @param resource $process
     */
    private function awaitMarker($process, string $marker, string $log): void
    {
        $deadline = microtime(true) + 30.0;

        while (!file_exists($marker)) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                self::fail('R6 — the doomed writer exited before it reached the kill: '.$this->log($log));
            }
            if (microtime(true) > $deadline) {
                self::fail('R6 — the doomed writer never reached its writes within 30s: '.$this->log($log));
            }

            usleep(5_000);
        }
    }

    /**
     * @param resource $process
     *
     * @return array{running: bool, signaled: bool, termsig: int, exitcode: int}
     */
    private function awaitExit($process, string $log): array
    {
        $deadline = microtime(true) + 30.0;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                self::fail('R6 — the doomed writer did not die within 30s: '.$this->log($log));
            }

            usleep(5_000);
        }

        proc_close($process);

        return [
            'running' => false,
            'signaled' => $status['signaled'],
            'termsig' => $status['termsig'],
            'exitcode' => $status['exitcode'],
        ];
    }

    /**
     * A deadline that fails without killing the child is not a deadline: the orphan still holds its
     * row lock, so the DELETE in tearDown blocks and CI hangs instead of failing in 30s.
     *
     * @param resource $process
     */
    private function terminate($process): void
    {
        // awaitExit() closes it on the happy path; a closed resource is not a resource.
        if (!\is_resource($process)) {
            return;
        }

        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }

        proc_close($process);
    }

    private function log(string $path): string
    {
        if (!is_file($path)) {
            return '(the writer produced no output)';
        }

        return trim((string) file_get_contents($path));
    }

    private function approvalStatus(Connection $connection): string
    {
        return (string) $connection->fetchOne(
            'SELECT approval_status FROM storefront_product_view WHERE slug = :slug',
            ['slug' => self::PRODUCT],
        );
    }

    private function auditRows(Connection $connection): int
    {
        return (int) $connection->fetchOne(
            'SELECT count(*) FROM catalog_audit_log WHERE target_type = :type AND target_slug = :slug',
            ['type' => 'product', 'slug' => self::PRODUCT],
        );
    }

    /**
     * A connection of its own to the same database: borrowing the handler's would share its
     * transaction and see everything it has not committed.
     */
    private function observer(): Connection
    {
        if (null === $this->observer) {
            $this->observer = DriverManager::getConnection(self::connection()->getParams());
        }

        return $this->observer;
    }

    private function removeRows(Connection $connection): void
    {
        CatalogRow::deleteProducts($connection, self::PRODUCT);
        CatalogRow::deleteAuditEntries($connection, self::PRODUCT);
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}
