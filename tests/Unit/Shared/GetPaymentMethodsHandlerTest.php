<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Query\GetPaymentMethods;
use App\Shared\Infrastructure\ReadModel\GetPaymentMethodsHandler;
use App\Shared\Infrastructure\ReadModel\PlatformSettingsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PgSQL\Driver as PgSqlDriver;
use PHPUnit\Framework\TestCase;

/**
 * solid Task 4.1 — the checkout's payment allow-list read goes through the query bus; the fake
 * connection answers no row, so the repository's default allow-list is what the handler returns.
 */
final class GetPaymentMethodsHandlerTest extends TestCase
{
    public function testAnswersTheOperatorEnabledPaymentMethods(): void
    {
        $repository = new PlatformSettingsRepository(new class extends Connection {
            public function __construct()
            {
                parent::__construct([], new PgSqlDriver());
            }

            public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
            {
                return false;
            }
        });

        $handler = new GetPaymentMethodsHandler($repository);

        self::assertSame(
            PlatformSettingsRepository::DEFAULT_PAYMENT_METHODS,
            $handler(new GetPaymentMethods()),
            'an unseeded row answers the same allow-list as a fresh install',
        );
    }
}
