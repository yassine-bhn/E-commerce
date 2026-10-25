<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Support;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;

/**
 * A throw-away PostgreSQL database used to execute a migration's down() without ever touching the
 * development or the test database — the safety contract of the R41 rollback test.
 *
 * The name is derived from the connection's current_database() plus a fixed suffix, and the
 * constructor refuses to hand out a handle unless three conditions hold:
 *
 *  1. the process environment is `test` — APP_ENV is read from $_SERVER, $_ENV and the kernel
 *     environment, and any of them saying otherwise aborts with a LogicException;
 *  2. the database it is derived from is itself a test database (`*_test` or `*_test<digits>`, the
 *     shape dbname_suffix produces), so a test-configured process that inherited a production DSN
 *     cannot create and force-drop a database inside a production cluster;
 *  3. the database is empty: create() reuses an empty probe and drops a probe a crashed run left
 *     behind (never reused), and refuses to continue if the probe still carries tables.
 *
 * Only this class issues DDL on the probe (`CREATE DATABASE` / `DROP DATABASE ... WITH (FORCE)`).
 * Migrations run through Doctrine's programmatic API bound to the probe connection, because the
 * console cannot be pointed at another database while the `when@test` dbname_suffix is active.
 */
final class MigrationProbeDatabase
{
    /** The migration whose down() this probe exists to execute. */
    public const string APPROVAL_MIGRATION = 'DoctrineMigrations\Version20261023000000';

    /** The migration that makes the customer e-mail unique case-insensitively (spec-run-12 R7-R9). */
    public const string EMAIL_UNIQUENESS_MIGRATION = 'DoctrineMigrations\Version20261024000000';

    private const string SUFFIX = '__migration_probe';

    private readonly string $name;

    private ?Connection $probeConnection = null;

    public function __construct(
        private readonly Connection $serverConnection,
        string $kernelEnvironment,
    ) {
        $this->assertTestEnvironment($kernelEnvironment);

        $currentDatabase = $this->currentDatabase();
        $this->name = $currentDatabase.self::SUFFIX;
        $this->assertDisposableName($currentDatabase);
    }

    /**
     * Named constructor: the kernel environment is the third source the safety contract reads.
     */
    public static function inTestEnvironment(Connection $serverConnection, string $kernelEnvironment): self
    {
        return new self($serverConnection, $kernelEnvironment);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Makes sure an empty probe database exists before a migration chain runs.
     *
     * An empty probe is reused; a probe left behind by a crashed run is dropped and recreated, so a
     * failure cannot wedge the suite. A database that still carries tables after that is refused:
     * create() never migrates on top of a state it did not create.
     */
    public function create(): void
    {
        $this->closeProbeConnection();

        if ($this->probeExists() && 0 !== $this->tableCount()) {
            $this->dropDatabase();
        }

        if (!$this->probeExists()) {
            $this->executeOnServer(sprintf('CREATE DATABASE %s', $this->quotedName()));
        }

        $tables = $this->tableCount();
        if (0 !== $tables) {
            throw new \LogicException(sprintf('Refusing to run migrations: the probe database "%s" still holds %d table(s).', $this->name, $tables));
        }
    }

    /**
     * The only way this helper disappears a database: DROP DATABASE IF EXISTS "<probe>" WITH (FORCE).
     *
     * tearDownAfterClass calls it, so no probe survives a run; FORCE closes a session a crashed
     * migration may have left open.
     */
    public function drop(): void
    {
        $this->closeProbeConnection();
        $this->dropDatabase();
    }

    public function connection(): Connection
    {
        return $this->probeConnection ??= DriverManager::getConnection($this->probeParameters());
    }

    /**
     * Runs the migration chain up to and including the approval migration, exactly like the CI step
     * does on a fresh test database.
     *
     * The target is named rather than resolved from the `latest` alias on purpose: the moment a newer
     * migration exists, `latest` would leave that migration applied above the one under test and
     * {@see self::rollBackTheApprovalMigration()}'s guard would abort a rollback that is in fact
     * correct. Newer migrations (the e-mail uniqueness one) therefore stay unapplied here and are
     * reached explicitly through {@see self::migrateUpTo()}.
     */
    public function migrateUpToTheApprovalMigration(): void
    {
        $this->migrateUpTo(self::APPROVAL_MIGRATION);
    }

    /**
     * Runs the chain up to and including one named version, skipping whatever is already applied.
     */
    public function migrateUpTo(string $version): void
    {
        $dependencyFactory = $this->dependencyFactory();
        $dependencyFactory->getMetadataStorage()->ensureInitialized();

        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));

        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    /**
     * Migrates down one version: the approval migration, which the chain just ran last.
     *
     * The guard refuses to execute the down() of a migration that is not the last one applied —
     * that would undo a step below a migration that stays applied, and the schema assertions would
     * then prove nothing.
     */
    public function rollBackTheApprovalMigration(): void
    {
        $this->rollBackTheLastMigration(self::APPROVAL_MIGRATION);
    }

    public function rollBackTheEmailUniquenessMigration(): void
    {
        $this->rollBackTheLastMigration(self::EMAIL_UNIQUENESS_MIGRATION);
    }

    private function rollBackTheLastMigration(string $version): void
    {
        $dependencyFactory = $this->dependencyFactory();
        $metadataStorage = $dependencyFactory->getMetadataStorage();
        $metadataStorage->ensureInitialized();

        $version = new Version($version);
        $executed = $metadataStorage->getExecutedMigrations();

        if (!$executed->hasMigration($version)) {
            throw new \LogicException(sprintf('The probe database never ran %s; migrate it up before rolling it back.', (string) $version));
        }

        if (!$executed->getLast()->getVersion()->equals($version)) {
            throw new \LogicException(sprintf('%s is not the last executed migration ("%s" is); the rollback must undo only the step under test.', (string) $version, (string) $executed->getLast()->getVersion()));
        }

        $plan = $dependencyFactory->getMigrationPlanCalculator()->getPlanForVersions([$version], Direction::DOWN);

        $dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    public function tableCount(): int
    {
        return $this->fetchInteger(
            'SELECT count(*) FROM information_schema.tables WHERE table_schema = current_schema()',
        );
    }

    public function hasTable(string $table): bool
    {
        return 1 === $this->fetchInteger(
            'SELECT count(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table],
        );
    }

    /**
     * Returns the requested columns that exist, in the requested order, so a test can assert the
     * whole set with one comparison instead of one assertion per column.
     *
     * @return list<string>
     */
    public function existingColumns(string $table, string ...$columns): array
    {
        $found = $this->connection()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name = :table AND column_name IN (:columns)',
            ['table' => $table, 'columns' => $columns],
            ['columns' => ArrayParameterType::STRING],
        );

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => in_array($column, $found, true),
        ));
    }

    public function hasConstraint(string $table, string $constraint): bool
    {
        return 1 === $this->fetchInteger(
            'SELECT count(*) FROM information_schema.table_constraints
                WHERE table_schema = current_schema() AND table_name = :table AND constraint_name = :constraint',
            ['table' => $table, 'constraint' => $constraint],
        );
    }

    /** Indexes are not constraints in PostgreSQL's catalogue of table constraints, so they are asked
     * separately, by the name the migrations give them. */
    public function hasIndex(string $table, string $index): bool
    {
        return 1 === $this->fetchInteger(
            'SELECT count(*) FROM pg_indexes
                WHERE schemaname = current_schema() AND tablename = :table AND indexname = :index',
            ['table' => $table, 'index' => $index],
        );
    }

    /**
     * The duplicate e-mails the unique index would refuse, as the migration itself reports them.
     *
     * @return list<string>
     */
    public function duplicateEmails(): array
    {
        return $this->connection()->fetchFirstColumn(
            'SELECT lower(email) FROM customer_profile GROUP BY lower(email) HAVING count(*) > 1',
        );
    }

    /** Whether the named index enforces uniqueness, which `hasIndex()` alone cannot tell. */
    public function indexIsUnique(string $table, string $index): bool
    {
        return 1 === $this->fetchInteger(
            'SELECT count(*) FROM pg_index i
                JOIN pg_class c ON c.oid = i.indexrelid
                JOIN pg_class t ON t.oid = i.indrelid
                WHERE c.relname = :index AND t.relname = :table AND i.indisunique',
            ['table' => $table, 'index' => $index],
        );
    }

    public function rowCount(string $table): int
    {
        return $this->fetchInteger(sprintf('SELECT count(*) FROM %s', $this->quote($table)));
    }

    /**
     * Reads one text value of the row identified by its slug; a missing row fails the test that
     * asks for it instead of silently returning an empty string.
     */
    public function stringValue(string $table, string $column, string $slug): string
    {
        return $this->fetchString(
            sprintf('SELECT %s FROM %s WHERE slug = :slug', $this->quote($column), $this->quote($table)),
            ['slug' => $slug],
        );
    }

    /**
     * Reads one boolean value of the row identified by its slug as a PHP boolean, whatever the
     * driver returns for a PostgreSQL boolean.
     */
    public function booleanValue(string $table, string $column, string $slug): bool
    {
        return 'true' === $this->fetchString(
            sprintf(
                "SELECT CASE WHEN %s THEN 'true' ELSE 'false' END FROM %s WHERE slug = :slug",
                $this->quote($column),
                $this->quote($table),
            ),
            ['slug' => $slug],
        );
    }

    private function dependencyFactory(): DependencyFactory
    {
        return DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => $this->migrationsDirectory()],
            ]),
            new ExistingConnection($this->connection()),
        );
    }

    private function migrationsDirectory(): string
    {
        return dirname(__DIR__, 4).'/migrations';
    }

    /**
     * @return array<string, mixed>
     */
    private function probeParameters(): array
    {
        $parameters = self::withoutResolvedName($this->serverConnection->getParams());
        $parameters['dbname'] = $this->name;

        return $parameters;
    }

    /**
     * DBAL resolves the configured name into `dbname` and keeps the two keys it came from, so a
     * probe name set afterwards would be overwritten by them; the test suffix must also not be
     * applied twice. Taking `array<string, mixed>` keeps the removed keys outside DBAL's declared
     * shape rather than unsupported offsets of it.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private static function withoutResolvedName(array $parameters): array
    {
        unset($parameters['dbname_suffix'], $parameters['url']);

        return $parameters;
    }

    private function currentDatabase(): string
    {
        $database = $this->serverConnection->fetchOne('SELECT current_database()');
        if (!is_string($database) || '' === $database) {
            throw new \LogicException('The connection did not report a current database.');
        }

        return $database;
    }

    private function assertTestEnvironment(string $kernelEnvironment): void
    {
        $environments = [
            'kernel.environment' => $kernelEnvironment,
            '$_SERVER[APP_ENV]' => $this->superGlobal('_SERVER'),
            '$_ENV[APP_ENV]' => $this->superGlobal('_ENV'),
        ];

        foreach ($environments as $source => $environment) {
            if (null !== $environment && 'test' !== $environment) {
                throw new \LogicException(sprintf('Migration probe refused: %s is "%s", only "test" may create or drop a disposable database.', $source, $environment));
            }
        }
    }

    private function superGlobal(string $name): ?string
    {
        $values = '_SERVER' === $name ? $_SERVER : $_ENV;
        $value = $values['APP_ENV'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The name carries the suffix by construction, so it can never equal an application database;
     * what has to be validated is the database the probe is derived from.
     */
    private function assertDisposableName(string $currentDatabase): void
    {
        if (1 === preg_match('/_test\d*$/', $currentDatabase)) {
            return;
        }

        throw new \LogicException(sprintf('Refusing to probe from "%s": a disposable probe may only be derived from a test database such as app_test.', $currentDatabase));
    }

    private function probeExists(): bool
    {
        return false !== $this->serverConnection->fetchOne(
            'SELECT 1 FROM pg_database WHERE datname = :name',
            ['name' => $this->name],
        );
    }

    private function dropDatabase(): void
    {
        $this->executeOnServer(sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $this->quotedName()));

        // WITH (FORCE) terminates every session on the probe, including the one create() opened to
        // count its tables a moment earlier. Keeping that handle would make the next read reuse a
        // socket the server already killed, which is why create() has to close it here.
        $this->closeProbeConnection();
    }

    private function executeOnServer(string $statement): void
    {
        $this->serverConnection->executeStatement($statement);
    }

    private function quotedName(): string
    {
        return $this->quote($this->name);
    }

    private function quote(string $identifier): string
    {
        return $this->serverConnection->quoteSingleIdentifier($identifier);
    }

    private function closeProbeConnection(): void
    {
        if (null === $this->probeConnection) {
            return;
        }

        $this->probeConnection->close();
        $this->probeConnection = null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function fetchInteger(string $sql, array $parameters = []): int
    {
        $value = $this->connection()->fetchOne($sql, $parameters);

        if (!is_int($value) && !(is_string($value) && is_numeric($value))) {
            throw new \LogicException(sprintf('Expected an integer from the probe, got %s.', get_debug_type($value)));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function fetchString(string $sql, array $parameters = []): string
    {
        $value = $this->connection()->fetchOne($sql, $parameters);

        if (!is_string($value)) {
            throw new \LogicException(sprintf('Expected a string from the probe, got %s.', get_debug_type($value)));
        }

        return $value;
    }
}
