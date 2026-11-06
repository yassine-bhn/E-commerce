<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Dto\AddressData;
use App\Identity\Application\Dto\QuoteRequestData;
use App\Identity\Application\Port\CustomerAddressStore;
use App\Identity\Application\Port\GoogleAccountLinker;
use App\Identity\Application\Port\IdentityAuditPort;
use App\Identity\Application\Port\NewsletterStore;
use App\Identity\Application\Port\PasswordResetStore;
use App\Identity\Application\Port\PasswordResetTokenStore;
use App\Identity\Application\Port\QuoteRequestStore;
use App\Identity\Application\Port\WishlistStore;
use App\Identity\Infrastructure\Persistence\DbalCustomerAddressStore;
use App\Identity\Infrastructure\Persistence\DbalGoogleAccountLinker;
use App\Identity\Infrastructure\Persistence\DbalIdentityAuditPort;
use App\Identity\Infrastructure\Persistence\DbalIdentityIntakeStore;
use App\Identity\Infrastructure\Persistence\DbalPasswordResetStore;
use App\Identity\Infrastructure\Persistence\DbalQuoteRequestStore;
use App\Identity\Infrastructure\Persistence\DbalWishlistStore;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * solid Task 3.1 — the remaining Identity adapters against PostgreSQL: each write lands the
 * former handler SQL's row, and the read-side contracts answer what the handlers expect.
 */
final class IdentityAdapterStoresTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdCustomerIds = [];

    /** @var list<string> */
    private array $createdAddressIds = [];

    /** @var list<string> */
    private array $createdContactIds = [];

    /** @var list<string> */
    private array $createdQuoteIds = [];

    /** @var list<string> */
    private array $createdNewsletterEmails = [];

    /** @var list<string> */
    private array $createdWishlistCustomerIds = [];

    /** @var list<string> */
    private array $createdAuditIds = [];

    protected function tearDown(): void
    {
        $connection = self::connection();

        if ([] !== $this->createdAuditIds) {
            $connection->executeStatement(
                'DELETE FROM identity_audit_log WHERE id IN (:ids)',
                ['ids' => $this->createdAuditIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->createdWishlistCustomerIds) {
            $connection->executeStatement(
                'DELETE FROM storefront_wishlist_item WHERE customer_id IN (:ids)',
                ['ids' => $this->createdWishlistCustomerIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->createdNewsletterEmails) {
            $connection->executeStatement(
                'DELETE FROM storefront_newsletter_subscriber WHERE email IN (:emails)',
                ['emails' => $this->createdNewsletterEmails],
                ['emails' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->createdQuoteIds) {
            $connection->executeStatement(
                'DELETE FROM identity_quote_requests WHERE id IN (:ids)',
                ['ids' => $this->createdQuoteIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
        if ([] !== $this->createdContactIds) {
            $connection->executeStatement(
                'DELETE FROM storefront_contact_message WHERE id IN (:ids)',
                ['ids' => $this->createdContactIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }
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
        $this->createdContactIds = [];
        $this->createdQuoteIds = [];
        $this->createdNewsletterEmails = [];
        $this->createdWishlistCustomerIds = [];
        $this->createdAuditIds = [];

        parent::tearDown();
    }

    public function testCustomerAddressStoreSavesUpdatesAndDeletesOnlyTheOwnedRow(): void
    {
        $store = self::getStore(CustomerAddressStore::class, DbalCustomerAddressStore::class);
        $customerId = $this->newCustomer();
        $addressId = Uuid::v7()->toRfc4122();
        $this->createdAddressIds[] = $addressId;

        $store->save(new AddressData(
            id: $addressId,
            customerId: $customerId,
            name: 'Hana Ben Salah',
            phone: '+216 71 333 001',
            street: '12 Rue de la Liberte',
            city: 'Tunis',
            governorate: 'Tunis',
            zip: '1000',
        ));
        self::assertSame('Tunis', $this->addressCity($addressId), 'the address row is written');

        $store->save(new AddressData(
            id: $addressId,
            customerId: $customerId,
            name: 'Hana Ben Salah',
            phone: '+216 71 333 001',
            street: '12 Rue de la Liberte',
            city: 'Sfax',
            governorate: 'Sfax',
            zip: '3000',
        ));
        self::assertSame('Sfax', $this->addressCity($addressId), 'the same id updates the row');

        $store->delete($addressId, $customerId);
        self::assertFalse($this->addressCity($addressId), 'the owned row is deleted');
    }

    public function testGoogleAccountLinkerLinksThenRegistersAndSurvivesTheAddressRace(): void
    {
        $linker = self::getStore(GoogleAccountLinker::class, DbalGoogleAccountLinker::class);
        $googleId = 'google-'.substr(Uuid::v7()->toRfc4122(), 0, 12);

        self::assertFalse($linker->isLinked($googleId), 'the identity starts unlinked');

        $ownerId = $this->newCustomer('google.owner@example.com');
        self::assertTrue($linker->linkByEmail('GOOGLE.OWNER@example.com', $googleId), 'an existing account is linked');
        $ownerRow = $this->profileRow($ownerId);
        self::assertIsArray($ownerRow);
        self::assertSame($googleId, $ownerRow['google_id']);

        $newId = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $newId;
        $linker->register($newId, 'Nouveau Visiteur', 'google.new@example.com', $googleId.'-new');
        $newRow = $this->profileRow($newId);
        self::assertIsArray($newRow);
        self::assertSame('google.new@example.com', $newRow['email'], 'a new account is created');

        // The raced registration: the address is taken, the adapter treats it as "already there".
        $lostId = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $lostId;
        $linker->register($lostId, 'Perdant', 'GOOGLE.OWNER@example.com', $googleId.'-lost');
        self::assertSame($ownerId, $this->ownerOfAddress('google.owner@example.com'), 'the lost race wrote no second account');
    }

    public function testPasswordResetStoreAndTokenLifecycle(): void
    {
        $store = self::getStore(PasswordResetStore::class, DbalPasswordResetStore::class);
        $tokens = self::getStore(PasswordResetTokenStore::class, DbalPasswordResetStore::class);
        $email = sprintf('reset.%s@example.com', substr(Uuid::v7()->toRfc4122(), 0, 8));
        $customerId = $this->newCustomer($email);

        $found = $store->customerByEmail(strtoupper($email));
        self::assertNotNull($found, 'the lookup is case-insensitive');
        self::assertSame($customerId, $found['id']);
        self::assertSame($email, $found['email']);
        self::assertNull($store->customerByEmail('missing@example.com'));

        $tokens->issue($customerId, 'hash-t2-1');
        self::assertSame($customerId, $tokens->consume('hash-t2-1'), 'a fresh token is consumed once');
        self::assertNull($tokens->consume('hash-t2-1'), 'a used token is refused');

        $tokens->issue($customerId, 'hash-t2-2');
        $tokens->issue($customerId, 'hash-t2-3');
        $tokens->invalidateActive($customerId);
        self::assertNull($tokens->consume('hash-t2-2'), 'a superseded token is refused');
        self::assertNull($tokens->consume('hash-t2-3'));

        $store->updatePassword($customerId, password_hash('Nouveau!MotDePasse9', PASSWORD_BCRYPT));
        $row = $this->profileRow($customerId);
        self::assertIsArray($row);
        self::assertTrue(password_verify('Nouveau!MotDePasse9', (string) $row['password_hash']));
    }

    public function testContactAndNewsletterIntakeWrites(): void
    {
        $contact = self::getStore(NewsletterStore::class, DbalIdentityIntakeStore::class);
        $messages = self::getStore(\App\Identity\Application\Port\ContactMessageStore::class, DbalIdentityIntakeStore::class);

        $contactId = Uuid::v7()->toRfc4122();
        $this->createdContactIds[] = $contactId;
        $messages->store($contactId, 'Visiteur', 'visiteur@example.com', '+216 71 444 001', 'Question', 'Bonjour');
        $row = self::connection()->fetchAssociative(
            'SELECT name, subject FROM storefront_contact_message WHERE id = :id',
            ['id' => $contactId],
        );
        self::assertIsArray($row);
        self::assertSame('Question', $row['subject']);

        $email = sprintf('newsletter.%s@example.com', substr(Uuid::v7()->toRfc4122(), 0, 8));
        $this->createdNewsletterEmails[] = $email;
        $contact->subscribe(Uuid::v7()->toRfc4122(), $email);
        $contact->subscribe(Uuid::v7()->toRfc4122(), strtoupper($email));
        self::assertSame(1, $this->newsletterCount($email), 'a repeat subscribe is a no-op');
    }

    public function testQuoteRequestStoreWritesTheIntakeRow(): void
    {
        $store = self::getStore(QuoteRequestStore::class, DbalQuoteRequestStore::class);
        $quoteId = Uuid::v7()->toRfc4122();
        $this->createdQuoteIds[] = $quoteId;

        $store->store(new QuoteRequestData(
            quoteId: $quoteId,
            userId: null,
            fullName: 'Client Potentiel',
            email: 'quote@example.com',
            phone: '+216 71 555 001',
            city: 'Tunis',
            category: 'cuisines',
            subcategories: ['Ilots', 'Facades'],
            message: 'Devis pour une cuisine',
        ));

        $row = self::connection()->fetchAssociative(
            'SELECT status, subcategories FROM identity_quote_requests WHERE id = :id',
            ['id' => $quoteId],
        );
        self::assertIsArray($row);
        self::assertSame('nouveau', $row['status']);
        self::assertSame(['Ilots', 'Facades'], json_decode((string) $row['subcategories'], true));
    }

    public function testWishlistStoreAddsOnceAndRemovesExactlyTheSlug(): void
    {
        $store = self::getStore(WishlistStore::class, DbalWishlistStore::class);
        $customerId = $this->newCustomer();

        $store->add(Uuid::v7()->toRfc4122(), $customerId, 'fauteuil');
        $store->add(Uuid::v7()->toRfc4122(), $customerId, 'fauteuil');
        self::assertSame(1, $this->wishlistCount($customerId, 'fauteuil'), 'a repeat add is a no-op');

        $store->add(Uuid::v7()->toRfc4122(), $customerId, 'pouf');
        $store->remove($customerId, 'fauteuil');
        self::assertSame(0, $this->wishlistCount($customerId, 'fauteuil'));
        self::assertSame(1, $this->wishlistCount($customerId, 'pouf'), 'only the targeted slug is removed');
    }

    public function testIdentityAuditPortWritesOneRowPerAction(): void
    {
        $audit = self::getStore(IdentityAuditPort::class, DbalIdentityAuditPort::class);
        $customerId = $this->newCustomer();
        $auditId = Uuid::v7()->toRfc4122();

        $audit->record('USER_BANNED', $customerId, 'admin-1', 'Comportement abusif');
        $row = self::connection()->fetchAssociative(
            'SELECT action, reason, actor_admin_id FROM identity_audit_log WHERE target_user_id = :customer ORDER BY created_at DESC LIMIT 1',
            ['customer' => $customerId],
        );
        self::assertIsArray($row);
        $this->createdAuditIds[] = (string) self::connection()->fetchOne(
            'SELECT id FROM identity_audit_log WHERE target_user_id = :customer ORDER BY created_at DESC LIMIT 1',
            ['customer' => $customerId],
        );
        self::assertSame('USER_BANNED', $row['action']);
        self::assertSame('Comportement abusif', $row['reason']);
        self::assertSame('admin-1', $row['actor_admin_id']);
    }

    /**
     * @template T of object
     *
     * @phpstan-param class-string $port
     * @phpstan-param class-string<T> $concrete
     *
     * @phpstan-return T
     */
    private function getStore(string $port, string $concrete): object
    {
        self::bootKernel();
        $resolved = static::getContainer()->get($port);
        self::assertInstanceOf($concrete, $resolved, sprintf('%s resolves to %s', $port, $concrete));

        return $resolved;
    }

    private function newCustomer(?string $email = null): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->createdCustomerIds[] = $id;
        self::connection()->insert('customer_profile', [
            'id' => $id,
            'name' => 'Compte Adaptateur',
            'email' => $email ?? sprintf('adapter.%s@example.com', substr($id, 0, 8)),
            'phone' => '',
        ]);

        return $id;
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

    private function ownerOfAddress(string $email): ?string
    {
        $id = self::connection()->fetchOne(
            'SELECT id FROM customer_profile WHERE lower(email) = lower(:email)',
            ['email' => $email],
        );

        return false === $id || null === $id ? null : (string) $id;
    }

    private function addressCity(string $addressId): string|false
    {
        $city = self::connection()->fetchOne(
            'SELECT city FROM customer_address WHERE id = :id',
            ['id' => $addressId],
        );

        return false === $city ? false : (string) $city;
    }

    private function newsletterCount(string $email): int
    {
        return (int) self::connection()->fetchOne(
            'SELECT COUNT(*) FROM storefront_newsletter_subscriber WHERE lower(email) = lower(:email)',
            ['email' => $email],
        );
    }

    private function wishlistCount(string $customerId, string $slug): int
    {
        return (int) self::connection()->fetchOne(
            'SELECT COUNT(*) FROM storefront_wishlist_item WHERE customer_id = :customer AND product_slug = :slug',
            ['customer' => $customerId, 'slug' => $slug],
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
