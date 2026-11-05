<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Query\GetCustomerProfile;
use App\Identity\Application\Query\ListCustomerAddresses;
use App\Shared\Application\Pagination\Page;
use App\Shared\Application\Pagination\PageRequest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * SRP-001 split (Task 2.4) — the profile and address reads are dispatched through the real
 * query.bus against PostgreSQL; the finder's row shape, search filter and pagination survive
 * the one-handler-per-class split unchanged.
 */
final class CustomerProfileFinderHandlersTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdCustomerIds = [];

    /** @var list<string> */
    private array $createdAddressIds = [];

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->createdAddressIds) {
            $connection->executeStatement(
                'DELETE FROM customer_address WHERE id IN (:ids)',
                ['ids' => $this->createdAddressIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        if ([] !== $this->createdCustomerIds) {
            $connection->executeStatement(
                'DELETE FROM customer_profile WHERE id IN (:ids)',
                ['ids' => $this->createdCustomerIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $this->createdCustomerIds = [];
        $this->createdAddressIds = [];

        parent::tearDown();
    }

    public function testGetCustomerProfileReturnsTheRowShapeThroughTheQueryBus(): void
    {
        $customerId = $this->newCustomerId();
        self::connection()->insert('customer_profile', [
            'id' => $customerId,
            'name' => 'Hana Ben Salah',
            'email' => sprintf('finder-%s@example.com', substr($customerId, 0, 8)),
            'phone' => '+216 71 111 001',
            'password_hash' => password_hash('Integration!Secret42', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
            'account_type' => 'professionnel',
            'company' => 'Ben Salah SARL',
            'mf' => '7654321B',
        ]);

        $profile = $this->queryBus()->dispatch(new GetCustomerProfile(customerId: $customerId));

        $handled = $profile->last(HandledStamp::class);
        self::assertNotNull($handled, 'the query must be handled on query.bus');
        $view = $handled->getResult();
        self::assertIsArray($view);
        self::assertSame($customerId, $view['id']);
        self::assertSame('Hana Ben Salah', $view['name']);
        self::assertSame('professionnel', $view['type']);
        self::assertSame('Ben Salah SARL', $view['company']);
        self::assertSame('7654321B', $view['mf']);
    }

    public function testGetCustomerProfileReturnsNullForAnUnknownCustomer(): void
    {
        $handled = $this->queryBus()->dispatch(new GetCustomerProfile(customerId: Uuid::v7()->toRfc4122()));

        $result = $handled->last(HandledStamp::class)?->getResult();
        self::assertNull($result, 'a missing profile reads as null, not as an error');
    }

    public function testListCustomerAddressesPaginatesAndFilters(): void
    {
        $customerId = $this->newCustomerId();
        $this->insertAddress($customerId, 'Salon Principal', 'Tunis');
        $this->insertAddress($customerId, 'Bureau Sfax', 'Sfax');
        $this->insertAddress($customerId, 'Depot Ariana', 'Ariana');

        $page = $this->askList($customerId, new PageRequest(1, 2, 100));
        self::assertSame(3, $page->total, 'the filter-free count sees every address');
        self::assertCount(2, $page->items, 'the requested page size is respected');

        $filtered = $this->askList($customerId, new PageRequest(1, 20, 100), 'sfax');
        self::assertSame(1, $filtered->total, 'the search matches city case-insensitively');
        self::assertSame('Bureau Sfax', $filtered->items[0]['name']);

        $escaped = $this->askList($customerId, new PageRequest(1, 20, 100), '%');
        self::assertSame(0, $escaped->total, 'LIKE wildcards in the search term are escaped');
    }

    private function askList(string $customerId, PageRequest $page, ?string $q = null): Page
    {
        $envelope = $this->queryBus()->dispatch(new ListCustomerAddresses(
            customerId: $customerId,
            page: $page,
            q: $q,
        ));
        $result = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(Page::class, $result);

        return $result;
    }

    private function insertAddress(string $customerId, string $name, string $city): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->createdAddressIds[] = $id;
        self::connection()->insert('customer_address', [
            'id' => $id,
            'customer_id' => $customerId,
            'name' => $name,
            'phone' => '+216 71 111 002',
            'street' => 'Street',
            'city' => $city,
            'governorate' => $city,
            'zip' => '1000',
        ]);
    }

    private function newCustomerId(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $id;

        return $id;
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }

    private function queryBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.query');
        assert($bus instanceof MessageBusInterface);

        return $bus;
    }
}
