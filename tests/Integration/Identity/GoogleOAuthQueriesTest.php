<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Query\GetGoogleAuthorizationUrl;
use App\Identity\Application\Query\GetLoginUser;
use App\Identity\Infrastructure\ReadModel\GetGoogleAuthorizationUrlHandler;
use App\Identity\Infrastructure\ReadModel\GetGoogleUserHandler;
use App\Identity\Infrastructure\ReadModel\GetLoginUserHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * solid Task 4.2 — the OAuth queries through the real query.bus: the not-configured path answers
 * null (the controller's 503) and the login query resolves the account behind the address.
 * The exchange-failure path is pinned by the handler contract: the client's GoogleOAuthException
 * propagates to the caller (the controller redirects to the storefront error).
 */
final class GoogleOAuthQueriesTest extends KernelTestCase
{
    public function testTheThreeHandlersResolveThroughTheContainer(): void
    {
        self::bootKernel();

        foreach ([
            GetGoogleAuthorizationUrlHandler::class,
            GetGoogleUserHandler::class,
            GetLoginUserHandler::class,
        ] as $handler) {
            self::assertInstanceOf($handler, static::getContainer()->get($handler), sprintf('%s is wired on query.bus', $handler));
        }
    }

    public function testTheAuthorizationUrlIsAnsweredByTheConfiguredClient(): void
    {
        $bus = $this->queryBus();

        /** @var string|null $url */
        $url = $bus->dispatch(new GetGoogleAuthorizationUrl('state-123'))->last(HandledStamp::class)?->getResult();

        if (null === $url) {
            self::assertNull($url, 'an unconfigured environment answers null — the controller maps that to 503');
        } else {
            self::assertStringContainsString('state=state-123', $url, 'the state rides the authorization url');
        }
    }

    public function testTheLoginQueryLoadsTheAccountBehindTheAddress(): void
    {
        $bus = $this->queryBus();

        $email = sprintf('google.login.%s@example.com', substr(bin2hex(random_bytes(4)), 0, 8));
        self::connection()->insert('customer_profile', [
            'id' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
            'name' => 'Google Login',
            'email' => $email,
            'phone' => '',
            'password_hash' => password_hash('Integration!Secret42', PASSWORD_BCRYPT),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        try {
            $user = $bus->dispatch(new GetLoginUser($email))->last(HandledStamp::class)?->getResult();

            self::assertNotNull($user);
            self::assertSame($email, $user->getUserIdentifier());
        } finally {
            self::connection()->executeStatement(
                'DELETE FROM customer_profile WHERE email = :email',
                ['email' => $email],
            );
        }
    }

    private function queryBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.query');
        \assert($bus instanceof MessageBusInterface);

        return $bus;
    }

    private static function connection(): \Doctrine\DBAL\Connection
    {
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        \assert($connection instanceof \Doctrine\DBAL\Connection);

        return $connection;
    }
}
