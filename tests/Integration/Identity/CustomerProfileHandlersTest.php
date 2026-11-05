<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\DeleteCustomerAddress;
use App\Identity\Application\Command\SaveCustomerAddress;
use App\Identity\Application\Command\UpdateCustomerProfile;
use App\Identity\Domain\Model\EmailAlreadyUsedException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SRP-001 split (Task 2.2) — each profile/address command is dispatched through the real
 * command.bus against PostgreSQL and lands in the same table the controller flow uses, so the
 * one-class-per-message split is behavior-preserving.
 */
final class CustomerProfileHandlersTest extends KernelTestCase
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

    public function testUpdateCustomerProfileUpsertsTheProfileRow(): void
    {
        $customerId = $this->newCustomerId();

        $this->commandBus()->dispatch(new UpdateCustomerProfile(
            customerId: $customerId,
            name: 'Amine Trabelsi',
            email: sprintf('profile-%s@example.com', substr($customerId, 0, 8)),
            phone: '+216 71 000 001',
            accountType: 'professionnel',
            company: 'Trabelsi SARL',
            mf: '1234567A',
        ));

        $row = self::connection()->fetchAssociative(
            'SELECT name, email, phone, account_type, company, mf FROM customer_profile WHERE id = :id',
            ['id' => $customerId],
        );
        self::assertNotFalse($row, 'the profile row is written');
        self::assertSame('Amine Trabelsi', $row['name']);
        self::assertSame('professionnel', $row['account_type']);
        self::assertSame('Trabelsi SARL', $row['company']);
        self::assertSame('1234567A', $row['mf']);
    }

    public function testUpdateCustomerProfileKeepsTheEmailCollisionSemantics(): void
    {
        $first = $this->newCustomerId();
        $second = $this->newCustomerId();
        $email = sprintf('shared-%s@example.com', substr($first, 0, 8));

        $this->commandBus()->dispatch(new UpdateCustomerProfile(
            customerId: $first,
            name: 'First Owner',
            email: strtoupper($email),
            phone: '+216 71 000 002',
        ));

        try {
            $this->commandBus()->dispatch(new UpdateCustomerProfile(
                customerId: $second,
                name: 'Second Claimant',
                email: $email,
                phone: '+216 71 000 003',
            ));
            self::fail('claiming an email another profile owns must be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(
                EmailAlreadyUsedException::class,
                $exception->getPrevious(),
                'the handler preserves the EmailAlreadyUsedException mapping through the bus',
            );
        }
    }

    public function testSaveCustomerAddressUpsertsThenUpdatesTheSameRow(): void
    {
        $customerId = $this->newCustomerId();
        $addressId = Uuid::v7()->toRfc4122();
        $this->createdAddressIds[] = $addressId;

        $this->commandBus()->dispatch(new SaveCustomerAddress(
            id: $addressId,
            customerId: $customerId,
            name: 'Amine Trabelsi',
            phone: '+216 71 000 004',
            street: '12 Rue de la Liberte',
            city: 'Tunis',
            governorate: 'Tunis',
            zip: '1000',
        ));

        $row = self::connection()->fetchAssociative(
            'SELECT city, zip FROM customer_address WHERE id = :id',
            ['id' => $addressId],
        );
        self::assertNotFalse($row, 'the address row is written');
        self::assertSame('Tunis', $row['city']);

        $this->commandBus()->dispatch(new SaveCustomerAddress(
            id: $addressId,
            customerId: $customerId,
            name: 'Amine Trabelsi',
            phone: '+216 71 000 004',
            street: '12 Rue de la Liberte',
            city: 'Sfax',
            governorate: 'Sfax',
            zip: '3000',
        ));

        $row = self::connection()->fetchAssociative(
            'SELECT city, zip FROM customer_address WHERE id = :id',
            ['id' => $addressId],
        );
        self::assertNotFalse($row);
        self::assertSame('Sfax', $row['city'], 'a second save with the same id updates the row');
        self::assertSame('3000', $row['zip']);
    }

    public function testDeleteCustomerAddressRemovesTheOwnedRow(): void
    {
        $customerId = $this->newCustomerId();
        $addressId = Uuid::v7()->toRfc4122();
        $this->createdAddressIds[] = $addressId;

        $this->commandBus()->dispatch(new SaveCustomerAddress(
            id: $addressId,
            customerId: $customerId,
            name: 'Amine Trabelsi',
            phone: '+216 71 000 005',
            street: '9 Avenue Habib Bourguiba',
            city: 'Tunis',
            governorate: 'Tunis',
            zip: '1001',
        ));

        $this->commandBus()->dispatch(new DeleteCustomerAddress(id: $addressId, customerId: $customerId));

        $count = self::connection()->fetchOne(
            'SELECT COUNT(*) FROM customer_address WHERE id = :id',
            ['id' => $addressId],
        );
        self::assertSame(0, (int) $count, 'the address is deleted');
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

    private function commandBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.command');
        assert($bus instanceof MessageBusInterface);

        return $bus;
    }
}
