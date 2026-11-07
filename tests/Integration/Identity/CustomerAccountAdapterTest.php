<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Dto\CustomerProfileData;
use App\Identity\Application\Dto\NewCustomer;
use App\Identity\Application\Port\CustomerAccountReader;
use App\Identity\Application\Port\CustomerDeletionReader;
use App\Identity\Application\Port\CustomerDeletionWriter;
use App\Identity\Application\Port\CustomerProfileStore;
use App\Identity\Application\Port\CustomerRegistrar;
use App\Identity\Application\Port\CustomerRoleStore;
use App\Identity\Application\Port\CustomerStatusStore;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use App\Identity\Infrastructure\Persistence\DbalCustomerAccountAdapter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * solid Task 3.1 — the account adapter against PostgreSQL: each port method lands exactly the
 * row shape the former inline handler SQL wrote, and the e-mail collision mapping stays a
 * property of the unique index.
 */
final class CustomerAccountAdapterTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdCustomerIds = [];

    /** @var list<string> */
    private array $createdOrderIds = [];

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->createdOrderIds) {
            $connection->executeStatement(
                'DELETE FROM ordering_orders WHERE id IN (:ids)',
                ['ids' => $this->createdOrderIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->createdCustomerIds) {
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->createdCustomerIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        $this->createdCustomerIds = [];
        $this->createdOrderIds = [];

        parent::tearDown();
    }

    public function testFindReturnsTheRecordShapeAndNullForAMissingCustomer(): void
    {
        $adapter = $this->adapter();
        $id = $this->newCustomer('reader@example.com', 'ROLE_USER', 'active');

        $record = $adapter->find($id);
        self::assertNotNull($record);
        self::assertSame($id, $record->id);
        self::assertSame('reader@example.com', $record->email);
        self::assertSame('ROLE_USER', $record->role);
        self::assertSame('active', $record->status);
        self::assertNull($record->googleId);

        self::assertNull($adapter->find(Uuid::v7()->toRfc4122()), 'a missing customer reads as null');
    }

    public function testActiveSuperAdminCountCountsOnlyUnbannedSuperAdmins(): void
    {
        $adapter = $this->adapter();
        $bannedSuperAdmin = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $bannedSuperAdmin;
        self::connection()->insert('customer_profile', [
            'id' => $bannedSuperAdmin,
            'name' => 'Banned Super',
            'email' => sprintf('banned-super-%s@example.com', substr($bannedSuperAdmin, 0, 8)),
            'phone' => '',
            'role' => 'ROLE_SUPER_ADMIN',
            'status' => 'banned',
        ]);

        $count = $adapter->activeSuperAdminCount();
        $extra = Uuid::v7()->toRfc4122();
        $this->newCustomer(sprintf('extra-super-%s@example.com', substr($extra, 0, 8)), 'ROLE_SUPER_ADMIN', 'active');

        self::assertSame($count + 1, $adapter->activeSuperAdminCount(), 'the banned super admin is not counted');
    }

    public function testBanAndUnbanWriteTheStatusColumnsTheHandlerWrote(): void
    {
        $adapter = $this->adapter();
        $id = $this->newCustomer('ban-target@example.com');

        $adapter->ban($id, 'Comportement abusif', 'admin-1');

        $row = $this->profileRow($id);
        self::assertIsArray($row);
        self::assertSame('banned', $row['status']);
        self::assertSame('Comportement abusif', $row['ban_reason']);
        self::assertSame('admin-1', $row['banned_by']);
        self::assertNotNull($row['banned_at']);

        $adapter->unban($id);

        $row = $this->profileRow($id);
        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertNull($row['ban_reason']);
        self::assertNull($row['banned_by']);
        self::assertNull($row['banned_at']);
    }

    public function testChangeRoleUpdatesTheRoleAndTheRowTimestamp(): void
    {
        $adapter = $this->adapter();
        $id = $this->newCustomer('role-target@example.com');

        $adapter->changeRole($id, 'ROLE_ADMIN');

        $row = $this->profileRow($id);
        self::assertIsArray($row);
        self::assertSame('ROLE_ADMIN', $row['role']);
    }

    public function testAnonymizeRewritesTheProfileAndRemovesTheAddresses(): void
    {
        $adapter = $this->adapter();
        $id = $this->newCustomer('anon-target@example.com');
        $this->insertAddress($id, '12 Rue X');

        $adapter->anonymize($id, 'anonymized-deadbeef@system.local');

        $row = $this->profileRow($id);
        self::assertIsArray($row);
        self::assertSame('Utilisateur anonymisé', $row['name']);
        self::assertSame('anonymized-deadbeef@system.local', $row['email']);
        self::assertSame('anonymized', $row['status']);
        self::assertNull($row['company']);
        self::assertNull($row['mf']);
        self::assertSame(0, $this->addressCount($id), 'the saved addresses are removed');
    }

    public function testHardDeleteRemovesProfileAndAddresses(): void
    {
        $adapter = $this->adapter();
        $id = $this->newCustomer('hard-delete@example.com');
        $this->insertAddress($id, '9 Rue Y');

        $adapter->hardDelete($id);

        self::assertFalse($this->profileRow($id), 'the profile row is gone');
        self::assertSame(0, $this->addressCount($id));
    }

    public function testOrderCountCountsTheCustomerOrdersOnly(): void
    {
        $adapter = $this->adapter();
        $id = $this->newCustomer('with-orders@example.com');

        self::assertSame(0, $adapter->orderCount($id));

        $orderId = Uuid::v7()->toRfc4122();
        $this->createdOrderIds[] = $orderId;
        self::connection()->insert('ordering_orders', [
            'id' => $orderId,
            'customer_id' => $id,
            'status' => 'placed',
            'order_number' => 'ZG-ADAPT-'.substr($orderId, 0, 6),
            'total_minor' => 1000,
            'currency' => 'TND',
        ]);

        self::assertSame(1, $adapter->orderCount($id));
    }

    public function testRegisterInsertsTheAccountAndAnswersTheEmailCollision(): void
    {
        $adapter = $this->adapter();
        $email = sprintf('register.%s@example.com', bin2hex(random_bytes(6)));
        $registeredId = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $registeredId;

        $adapter->register(new NewCustomer(
            id: $registeredId,
            name: 'Premier Compte',
            email: $email,
            phone: '+216 71 222 001',
            accountType: 'particulier',
            company: null,
            mf: null,
            passwordHash: password_hash('MotDePasse!2026', PASSWORD_BCRYPT),
        ));

        try {
            $adapter->register(new NewCustomer(
                id: Uuid::v7()->toRfc4122(),
                name: 'Second Compte',
                email: strtoupper($email),
                phone: '',
                accountType: 'particulier',
                company: null,
                mf: null,
                passwordHash: 'x',
            ));
            self::fail('the unique index must refuse the second account for one address');
        } catch (EmailAlreadyUsedException $exception) {
            self::assertStringContainsStringIgnoringCase($email, $exception->getMessage());
        }
    }

    public function testProfileUpsertWritesAndUpdatesAndAnswersTheEmailCollision(): void
    {
        $adapter = $this->adapter();
        $id = $this->newCustomer('profile-upsert@example.com');

        $adapter->upsert(new CustomerProfileData(
            id: $id,
            name: 'Hana Ben Salah',
            email: 'profile.upsert@example.com',
            phone: '+216 71 222 002',
            accountType: 'professionnel',
            company: 'Ben Salah SARL',
            mf: '7654321B',
        ));

        $row = $this->profileRow($id);
        self::assertIsArray($row);
        self::assertSame('Hana Ben Salah', $row['name']);
        self::assertSame('professionnel', $row['account_type']);
        self::assertSame('7654321B', $row['mf']);

        try {
            $other = Uuid::v7()->toRfc4122();
            $this->createdCustomerIds[] = $other;
            self::connection()->insert('customer_profile', [
                'id' => $other,
                'name' => 'Autre Compte',
                'email' => 'another.account@example.com',
                'phone' => '',
            ]);
            $adapter->upsert(new CustomerProfileData(
                id: $other,
                name: 'Autre Compte',
                email: 'PROFILE.UPSERT@example.com',
                phone: '',
                accountType: 'particulier',
                company: null,
                mf: null,
            ));
            self::fail('claiming an address another profile owns must be refused');
        } catch (EmailAlreadyUsedException $exception) {
            self::assertStringContainsStringIgnoringCase('profile.upsert@example.com', $exception->getMessage());
        }
    }

    public function testEveryAccountPortResolvesToTheSameAdapterInstance(): void
    {
        $adapter = static::getContainer()->get(DbalCustomerAccountAdapter::class);

        foreach ([
            CustomerAccountReader::class,
            CustomerDeletionReader::class,
            CustomerDeletionWriter::class,
            CustomerProfileStore::class,
            CustomerRegistrar::class,
            CustomerRoleStore::class,
            CustomerStatusStore::class,
        ] as $port) {
            self::assertSame($adapter, static::getContainer()->get($port), sprintf('%s is the one adapter', $port));
        }
    }

    private function adapter(): DbalCustomerAccountAdapter
    {
        $adapter = static::getContainer()->get(DbalCustomerAccountAdapter::class);
        \assert($adapter instanceof DbalCustomerAccountAdapter);

        return $adapter;
    }

    private function newCustomer(string $email, string $role = 'ROLE_USER', string $status = 'active'): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $id;
        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Compte Adaptateur',
            'email' => $email,
            'phone' => '',
            'role' => $role,
            'status' => $status,
        ]);

        return $id;
    }

    private function insertAddress(string $customerId, string $street): void
    {
        self::connection()->insert('customer_address', [
            'id' => Uuid::v7()->toRfc4122(),
            'customer_id' => $customerId,
            'name' => 'Adresse Adaptateur',
            'phone' => '+216 71 222 003',
            'street' => $street,
            'city' => 'Tunis',
            'governorate' => 'Tunis',
            'zip' => '1000',
        ]);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function profileRow(string $id): array|false
    {
        return self::connection()->fetchAssociative(
            'SELECT * FROM customer_profile WHERE id = :id',
            ['id' => $id],
        );
    }

    private function addressCount(string $customerId): int
    {
        return (int) self::connection()->fetchOne(
            'SELECT COUNT(*) FROM customer_address WHERE customer_id = :customer',
            ['customer' => $customerId],
        );
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
