<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Application\Query\GetWhatsAppContact;
use App\Shared\Infrastructure\ReadModel\GetWhatsAppContactHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Routing\RouterInterface;

/**
 * R1 / R2 / R17 / R18 / R19 — the WhatsApp contact is answered on the real query.bus purely from the
 * WHATSAPP_* environment configuration: the platform_setting column never leaks in, and the read path
 * touches no row (QRY-001 / QRY-003).
 */
final class WhatsAppContactConfigurationTest extends KernelTestCase
{
    /** Deliberately not the configured number: it would be recognisable in the payload if the DB leaked. */
    private const string DECOY_DB_NUMBER = '+212611223344';

    private ?string $previousContactWhatsapp = null;

    protected function tearDown(): void
    {
        if (null !== $this->previousContactWhatsapp) {
            self::connection()->executeStatement(
                'UPDATE platform_setting SET contact_whatsapp = :value WHERE id = 1',
                ['value' => $this->previousContactWhatsapp],
            );
            $this->previousContactWhatsapp = null;
        }

        parent::tearDown();
    }

    public function testContactIsAnsweredFromTheConfiguredEnvironmentOnTheQueryBus(): void
    {
        $query = new GetWhatsAppContact();

        $contact = $this->dispatchContact($query);

        self::assertEqualsCanonicalizing(
            ['phoneNumber', 'message', 'chatUrl'],
            array_keys($contact),
            'R19 — the handler answers with exactly the three contract keys',
        );

        $configuredNumber = self::configuredValue('WHATSAPP_PHONE_NUMBER');
        $configuredMessage = self::configuredValue('WHATSAPP_DEFAULT_MESSAGE');

        self::assertSame(
            ltrim($configuredNumber, '+'),
            $contact['phoneNumber'],
            'R1/R7 — phoneNumber is the configured number with the optional leading "+" stripped',
        );
        self::assertMatchesRegularExpression(
            '/\A[0-9]{8,15}\z/',
            $contact['phoneNumber'],
            'R3/R7 — the answered number is digits only: no "+", no spaces, no separators',
        );
        self::assertSame(
            $configuredMessage,
            $contact['message'],
            'R1/R2 — the default message is the configured one, never a literal in src/**',
        );
        self::assertSame(
            'https://wa.me/'.$contact['phoneNumber'].'?text='.rawurlencode($configuredMessage),
            $contact['chatUrl'],
            'R9/R10 — chatUrl is generated per read from the digits and the percent-encoded message',
        );
    }

    public function testThePlatformSettingWhatsappColumnNeverLeaksIntoTheAnswer(): void
    {
        $query = new GetWhatsAppContact();
        $connection = self::connection();

        $this->previousContactWhatsapp = (string) $connection->fetchOne(
            'SELECT contact_whatsapp FROM platform_setting WHERE id = 1',
        );

        $beforeTheDecoy = $this->dispatchContact($query);

        $connection->executeStatement(
            'UPDATE platform_setting SET contact_whatsapp = :value WHERE id = 1',
            ['value' => self::DECOY_DB_NUMBER],
        );

        $afterTheDecoy = $this->dispatchContact($query);

        self::assertSame(
            $beforeTheDecoy,
            $afterTheDecoy,
            'R18 — writing platform_setting.contact_whatsapp changes nothing in the answered contact',
        );
        self::assertSame(
            ltrim(self::configuredValue('WHATSAPP_PHONE_NUMBER'), '+'),
            $afterTheDecoy['phoneNumber'],
            'R1/R18 — the number still comes from %env(WHATSAPP_PHONE_NUMBER)%, never from the DB column',
        );
    }

    public function testReadingTheContactWritesNothing(): void
    {
        $query = new GetWhatsAppContact();
        $connection = self::connection();

        // platform_setting is the only platform_* table in the schema (migrations 20261022000004/6).
        $rowsBefore = $connection->fetchAllAssociative('SELECT * FROM platform_setting ORDER BY id');
        $rowCountBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM platform_setting');

        $this->dispatchContact($query);

        self::assertSame(
            $rowsBefore,
            $connection->fetchAllAssociative('SELECT * FROM platform_setting ORDER BY id'),
            'QRY-001 — the read path never inserts, updates or deletes a platform_setting row',
        );
        self::assertSame(
            $rowCountBefore,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM platform_setting'),
            'QRY-001 — the platform table row count is unchanged by a read',
        );
    }

    /**
     * R17 — the answered contact is built without a persistence collaborator at all: the handler has
     * no Connection/EntityManager argument and its source imports no Doctrine, so "no database
     * access" is structural rather than an observation of one particular row set.
     */
    public function testTheHandlerHasNoPersistenceDependency(): void
    {
        $reflection = new \ReflectionClass(GetWhatsAppContactHandler::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        self::assertSame(['string', 'string'], $types, 'R17 — the handler receives configuration only.');
        self::assertStringNotContainsString(
            'Doctrine',
            (string) file_get_contents((string) $reflection->getFileName()),
            'QRY-003 — the read model touches no ORM or DBAL API.',
        );
    }

    /**
     * R22 — the route name the operator page and the README quote is the one the router exposes, so a
     * renamed route cannot leave the documentation lying.
     */
    public function testTheDocumentedRouteNameResolvesToTheDocumentedPath(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame(
            '/api/v1/storefront/contact/whatsapp',
            $router->generate('storefront_whatsapp_contact'),
        );
    }

    /** @return array{phoneNumber: string, message: string, chatUrl: string} */
    private function dispatchContact(GetWhatsAppContact $query): array
    {
        $envelope = $this->queryBus()->dispatch($query);
        $handled = $envelope->last(HandledStamp::class);
        self::assertNotNull($handled, 'R19 — the contact query is handled on the query.bus');
        self::assertSame([], $envelope->all(SentStamp::class), 'R17 — nothing is queued or sent to a transport.');

        $result = $handled->getResult();
        self::assertIsArray($result, 'R19 — the contact handler answers with an array payload');
        /** @var array{phoneNumber: string, message: string, chatUrl: string} $result */
        $contact = $result;

        return $contact;
    }

    private function queryBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.query');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private static function connection(): Connection
    {
        self::bootKernel();

        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * R2 — the expected values are read from the test environment, never hardcoded in the test, so a
     * configuration-only change (number or message) keeps this contract honest.
     */
    private static function configuredValue(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        self::assertIsString($value, sprintf('R1 — %s must be declared for the test environment.', $name));

        return $value;
    }
}
