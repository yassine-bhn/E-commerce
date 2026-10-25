<?php

declare(strict_types=1);

/**
 * spec-run-12 R6 — the writer this test kills.
 *
 * Started by tests/Integration/Catalog/CatalogAuditProcessIsolationTest.php as a separate PHP
 * process. It runs the real decision handler inside an outer transaction, so both the state UPDATE
 * and the audit INSERT exist on its connection, then writes a marker the parent watches for and
 * SIGKILLs itself: no COMMIT, no rollback, the socket closes. PostgreSQL rolls the transaction back,
 * so the parent can assert on a real database that a crash between the write and the commit leaves
 * neither the decision nor an audit entry behind.
 *
 * Not a test class: `tests/Support` is outside every PHPUnit suite, so nothing here is collected.
 */

use App\Catalog\Application\Command\DecideCatalogSubmission;
use App\Catalog\Application\CommandHandler\DecideCatalogSubmissionHandler;
use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Infrastructure\Audit\DbalCatalogAuditWriter;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

/** The reviewer the doomed decision would have been recorded against, if it had committed. */
const DOOMED_WRITER_ID = '01929312-0000-7000-8000-0000000000cc';

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments)) {
    $arguments = [];
}
$productSlug = isset($arguments[1]) && is_string($arguments[1]) ? $arguments[1] : '';
$marker = isset($arguments[2]) && is_string($arguments[2]) ? $arguments[2] : '';

if ('' === $productSlug || '' === $marker) {
    fwrite(STDERR, "usage: doomed_decision_writer.php <product-slug> <marker-file>\n");
    exit(2);
}

$kernel = new Kernel('test', true);
$kernel->boot();

$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof ContainerInterface) {
    fwrite(STDERR, "the test container is not reachable\n");
    exit(3);
}

$connection = $container->get(Connection::class);
if (!$connection instanceof Connection) {
    fwrite(STDERR, "the database connection is not reachable\n");
    exit(3);
}

// The outer transaction is the one that never ends: the handler joins it, so its own commit only
// releases a savepoint and the two writes stay uncommitted on this connection.
$connection->beginTransaction();

(new DecideCatalogSubmissionHandler($connection, new DbalCatalogAuditWriter($connection)))(
    new DecideCatalogSubmission(
        CatalogSubmissionKind::Product,
        $productSlug,
        CatalogDecision::Approve,
        DOOMED_WRITER_ID,
    ),
);

// Proof that the writes really happened before the kill, otherwise the parent's "nothing was
// committed" assertion would also pass on a writer that never wrote.
$status = $connection->fetchOne(
    'SELECT approval_status FROM storefront_product_view WHERE slug = :slug',
    ['slug' => $productSlug],
);
$trail = (int) $connection->fetchOne(
    'SELECT count(*) FROM catalog_audit_log WHERE target_slug = :slug',
    ['slug' => $productSlug],
);

if ('APPROVED' !== $status || 1 !== $trail) {
    fwrite(STDERR, sprintf("the doomed decision was not written: status=%s, audit rows=%d\n", var_export($status, true), $trail));
    exit(4);
}

// The crash is only meaningful from the process that owns the writes, so its id is required.
$pid = getmypid();
if (false === $pid) {
    fwrite(STDERR, "the writer has no process id, so the crash cannot be proven\n");
    exit(5);
}

if (false === file_put_contents($marker, (string) $pid)) {
    fwrite(STDERR, "the marker file could not be written\n");
    exit(5);
}

// SIGKILL: no shutdown function, no destructor, no rollback — what a killed worker looks like.
if (!function_exists('posix_kill') || !posix_kill($pid, 9)) {
    fwrite(STDERR, "the writer could not kill itself, so the crash is not proven\n");
    exit(6);
}

// Reaching this line means SIGKILL did not arrive; the fixture must never fake a crash.
fwrite(STDERR, "SIGKILL was ignored\n");
exit(7);
