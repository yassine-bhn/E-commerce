<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\RegisterCustomer;
use App\Identity\Application\CommandHandler\RegisterCustomerHandler;
use App\Identity\Application\EmailUniqueness;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use App\Tests\Integration\Catalog\Support\MigrationProbeDatabase;
use App\Tests\Integration\Identity\Support\UnseeingEmailCheckConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\Exception\AbortMigration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * spec-run-12 R7-R10 — `customer_profile.email` is unique the way the code compares it.
 *
 * Three claims, each proven where it can actually fail:
 *
 *  - the database refuses two accounts whose addresses differ only by case, because every identity
 *    lookup compares `lower(email)`;
 *  - a registration that loses the race still answers the conflict registration already answers,
 *    instead of the 500 a bare unique violation would become;
 *  - the migration that installs the constraint refuses to run on existing duplicates and changes
 *    no row, and its down() puts the plain index back so the older migration's down() still works.
 *
 * The migration half runs in the disposable probe database (MigrationProbeDatabase) because a
 * rollback may only ever be executed there, never on the test database.
 */
final class CustomerEmailUniquenessTest extends KernelTestCase
{
    private const string PLAIN_INDEX = 'idx_customer_profile_email';

    /** @var list<string> */
    private array $createdAddresses = [];

    private static ?MigrationProbeDatabase $probe = null;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::$probe = MigrationProbeDatabase::inTestEnvironment(self::connection(), $kernel->getEnvironment());
    }

    protected function tearDown(): void
    {
        foreach ($this->createdAddresses as $address) {
            self::connection()->executeStatement(
                'DELETE FROM customer_profile WHERE lower(email) = lower(:address)',
                ['address' => $address],
            );
        }
        $this->createdAddresses = [];

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$probe?->drop();
        self::$probe = null;

        parent::tearDownAfterClass();
    }

    public function testTheDatabaseRefusesTheSameAddressWrittenInAnotherCase(): void
    {
        $address = sprintf('case.%s@example.com', bin2hex(random_bytes(4)));
        $this->insertCustomer($address);

        try {
            // A second account, a different id, the same address read case-insensitively: exactly
            // what the code's `lower(email)` comparisons treat as one account.
            $this->insertCustomer(strtoupper($address));
            self::fail('the database accepted two accounts for one address, so the constraint is not case-insensitive');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertStringContainsString(
                EmailUniqueness::INDEX,
                $exception->getMessage(),
                'the conflict is the e-mail index, not another unique constraint',
            );
        }

        self::assertSame(1, $this->accountsFor($address), 'the refused row was not written');
    }

    public function testARacedRegistrationAnswersTheAlreadyUsedConflictInsteadOfA500(): void
    {
        $address = sprintf('race.%s@example.com', bin2hex(random_bytes(4)));
        $this->insertCustomer($address);

        // The pre-check is told to miss, which is what a concurrent registration sees between its
        // SELECT and its INSERT; the insert itself runs against the real database.
        $handler = new RegisterCustomerHandler(UnseeingEmailCheckConnection::wrapping(self::connection()));

        try {
            $handler(new RegisterCustomer(
                id: Uuid::v7()->toRfc4122(),
                name: 'Course',
                email: strtoupper($address),
                password: 'MotDePasse!2026',
                phone: '',
            ));
            self::fail('the raced registration wrote a second account for one address');
        } catch (EmailAlreadyUsedException $exception) {
            self::assertStringContainsStringIgnoringCase($address, $exception->getMessage(), 'the conflict names the address the customer typed');
        }

        self::assertSame(1, $this->accountsFor($address), 'the loser of the race wrote nothing');
    }

    public function testTheMigrationRefusesToRunOnDuplicatesAndChangesNoRow(): void
    {
        $probe = self::probe();
        $probe->create();
        $probe->migrateUpToTheApprovalMigration();

        $probe->connection()->insert('customer_profile', ['id' => Uuid::v4()->toRfc4122(), 'name' => 'Doublon A', 'email' => 'doublon@example.com']);
        $probe->connection()->insert('customer_profile', ['id' => Uuid::v4()->toRfc4122(), 'name' => 'Doublon B', 'email' => 'Doublon@Example.com']);
        $accountsBefore = $probe->rowCount('customer_profile');

        try {
            $probe->migrateUpTo(MigrationProbeDatabase::EMAIL_UNIQUENESS_MIGRATION);
            self::fail('the migration ran on a duplicated address, so it must have picked a winner');
        } catch (AbortMigration $abort) {
            // Recognisable, not reusable: the first character and the domain identify the address,
            // and every other character of the local part is masked — one mask per character, so the
            // operator can tell a short duplicate from a long one.
            self::assertMatchesRegularExpression(
                '/d\*{6}@example\.com/',
                $abort->getMessage(),
                'the operator is told which address blocks the migration, in a form they can recognise',
            );
            self::assertStringNotContainsString(
                'doublon@example.com',
                $abort->getMessage(),
                'the migration log is archived with the release, so the address itself never appears in it',
            );
            self::assertStringContainsString('No row was changed', $abort->getMessage(), 'the refusal says the data is untouched');
            self::assertStringContainsString('GROUP BY lower(email)', $abort->getMessage(), 'the message carries the query that finds the duplicates, so masking costs the operator nothing');
        }

        self::assertSame($accountsBefore, $probe->rowCount('customer_profile'), 'the refusal deletes nothing and merges nothing');
        self::assertSame(['doublon@example.com'], $probe->duplicateEmails(), 'both accounts still hold their address');
        self::assertFalse($probe->hasIndex('customer_profile', EmailUniqueness::INDEX), 'the unique index was not installed');
        self::assertTrue($probe->hasIndex('customer_profile', self::PLAIN_INDEX), 'the index the migration would have replaced is still there');
    }

    public function testTheRollbackRestoresThePlainIndexTheEarlierMigrationDrops(): void
    {
        $probe = self::probe();
        $probe->create();
        $probe->migrateUpToTheApprovalMigration();
        $probe->migrateUpTo(MigrationProbeDatabase::EMAIL_UNIQUENESS_MIGRATION);

        self::assertTrue($probe->hasIndex('customer_profile', EmailUniqueness::INDEX));
        self::assertTrue($probe->indexIsUnique('customer_profile', EmailUniqueness::INDEX), 'the address is unique, not merely indexed');
        self::assertFalse($probe->hasIndex('customer_profile', self::PLAIN_INDEX), 'up() replaced the plain index instead of stacking a second one');

        $probe->rollBackTheEmailUniquenessMigration();

        self::assertFalse($probe->hasIndex('customer_profile', EmailUniqueness::INDEX));
        self::assertTrue(
            $probe->hasIndex('customer_profile', self::PLAIN_INDEX),
            'down() recreates the index Version20261022000000::down() drops, so a full rollback still finds it',
        );
        self::assertFalse($probe->indexIsUnique('customer_profile', self::PLAIN_INDEX), 'the restored index is the plain one, not the constraint');
    }

    private function insertCustomer(string $address): string
    {
        $id = Uuid::v4()->toRfc4122();
        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Intégration e-mail',
            'email' => $address,
            'phone' => '',
        ]);
        $this->createdAddresses[] = $address;

        return $id;
    }

    private function accountsFor(string $address): int
    {
        $count = self::connection()->fetchOne(
            'SELECT count(*) FROM customer_profile WHERE lower(email) = lower(:address)',
            ['address' => $address],
        );

        return (int) $count;
    }

    private static function probe(): MigrationProbeDatabase
    {
        assert(self::$probe instanceof MigrationProbeDatabase);

        return self::$probe;
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}
