<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-0013 follow-up — an Origin allow-list guards state-changing /api/v1 requests.
 *
 * The guard is a request-side decision, so it is pinned at the HTTP boundary: a login that is
 * valid in every other respect must be refused before the firewall can open a session.
 *
 * The C4 block below is characterisation: it records what the guard must keep doing around the
 * refusal (the no-signal, read, preflight, non-API and allowed-origin paths), so that tightening
 * the allow-list later cannot silently swallow any of them.
 */
final class CsrfOriginGuardTest extends WebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple!42';

    /**
     * Deliberately outside the allow-list (the test environment allows http://localhost:3000).
     * Pinned here as data on purpose: a test that read the configured allow-list would agree
     * with a misconfigured deployment instead of pinning the refusal of a foreign origin.
     */
    private const FOREIGN_ORIGIN = 'http://evil.example';

    /** PHP's own session cookie name, and the one the test environment's mock storage uses. */
    private const SESSION_COOKIE_NAMES = ['PHPSESSID', 'MOCKSESSID'];

    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdProductSlugs = [];

    /** @var list<string> */
    private array $usedIdempotencyKeys = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdUserIds || [] !== $this->createdProductSlugs || [] !== $this->usedIdempotencyKeys) {
            $connection = self::connection();

            $this->deleteCreatedOrders($connection);
            $this->deleteProcessedCommands($connection);

            if ([] !== $this->createdProductSlugs) {
                $connection->executeStatement(
                    'DELETE FROM storefront_product_view WHERE slug IN (:slugs)',
                    ['slugs' => $this->createdProductSlugs],
                    ['slugs' => ArrayParameterType::STRING],
                );
                $this->createdProductSlugs = [];
            }

            if ([] !== $this->createdUserIds) {
                $connection->executeStatement(
                    'DELETE FROM identity_audit_log WHERE target_user_id IN (:ids)',
                    ['ids' => $this->createdUserIds],
                    ['ids' => ArrayParameterType::STRING],
                );
                $connection->executeStatement(
                    'DELETE FROM customer_profile WHERE id IN (:ids)',
                    ['ids' => $this->createdUserIds],
                    ['ids' => ArrayParameterType::STRING],
                );
                $this->createdUserIds = [];
            }
        }

        parent::tearDown();
    }

    public function testStateChangingRequestFromAForeignOriginIsRefused(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        // A valid login is a state-changing request: if the origin were ignored it would
        // authenticate the caller and hand out a session.
        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_ORIGIN' => self::FOREIGN_ORIGIN],
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            'A state-changing request from an origin outside the allow-list must be refused.',
        );

        $body = self::jsonBody($client);
        self::assertIsString($body['error'] ?? null, 'The refusal must answer the repo\'s {"error": ...} JSON body.');
        self::assertNotSame('', $body['error'], 'The refusal must carry a non-empty error message.');

        $this->assertNoSessionWasOpened($client);
    }

    /**
     * Review finding S1 — the router decodes what it is given, so the origin decision must judge
     * the same path: an encoded spelling of /api/v1 still routes to the state-changing endpoint,
     * and must therefore still be refused before the firewall sees it.
     */
    #[DataProvider('encodedLoginPaths')]
    public function testPercentEncodedApiPathIsStillJudgedByItsOrigin(string $path): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $client->jsonRequest(
            'POST',
            $path,
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_ORIGIN' => self::FOREIGN_ORIGIN],
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            sprintf('"%s" routes to a state-changing endpoint: its spelling must not decide whether the origin is judged.', $path),
        );

        $body = self::jsonBody($client);
        self::assertIsString($body['error'] ?? null, 'The refusal must answer the repo\'s {"error": ...} JSON body.');
        self::assertNotSame('', $body['error'], 'The refusal must carry a non-empty error message.');

        $this->assertNoSessionWasOpened($client);
    }

    /** @return iterable<string, array{string}> */
    public static function encodedLoginPaths(): iterable
    {
        yield 'encoded separator' => ['/api%2Fv1/auth/login'];
        yield 'encoded segment character' => ['/api/%76%31/auth/login'];
    }

    public function testStateChangingRequestFromTheConfiguredStorefrontOriginIsAllowed(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();
        $origin = self::configuredStorefrontOrigin();

        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_ORIGIN' => $origin],
        );

        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            'A state-changing request from the configured storefront origin must be allowed.',
        );

        // A 200 alone would also be produced by a login that never authenticated: the session
        // cookie is what proves the request reached the authenticator and completed the state change.
        self::assertCount(1, $this->emittedSessionCookieNames($client), 'An allowed login must hand out a session cookie.');
    }

    public function testStateChangingRequestFromTheOwnOriginIsAllowed(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        // Same-origin is not cross-site. The KernelBrowser base URL is http://localhost, and its
        // server parameters are only populated while a request runs, so this header is written
        // here instead of read back from the client before the login call.
        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_ORIGIN' => 'http://localhost'],
        );

        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            'A same-origin state-changing request must not be refused as cross-site.',
        );

        // A 200 that the guard let through is not enough: the login must have been served, and
        // the session cookie is what separates a served login from an unanswered one.
        self::assertCount(1, $this->emittedSessionCookieNames($client), 'A same-origin login must hand out a session cookie.');
    }

    /**
     * Review finding S6 — `null` is what a sandboxed document and some redirects announce. It
     * names no site, so it can never match the allow-list and must be refused rather than trusted.
     */
    public function testNullOriginIsRefused(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_ORIGIN' => 'null'],
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            'The opaque "null" origin names no site and must be refused.',
        );

        $body = self::jsonBody($client);
        self::assertIsString($body['error'] ?? null, 'The refusal must answer the repo\'s {"error": ...} JSON body.');
        self::assertNotSame('', $body['error'], 'The refusal must carry a non-empty error message.');

        $this->assertNoSessionWasOpened($client);
    }

    public function testStateChangingRequestWithAForeignRefererIsRefused(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        // Browsers omit Origin on some form submissions and announce the initiating site only
        // through Referer, so the fallback signal must judge the request just as strictly.
        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_REFERER' => self::FOREIGN_ORIGIN.'/some/page'],
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            'A state-changing request whose Referer is outside the allow-list must be refused.',
        );

        $body = self::jsonBody($client);
        self::assertIsString($body['error'] ?? null, 'The refusal must answer the repo\'s {"error": ...} JSON body.');
        self::assertNotSame('', $body['error'], 'The refusal must carry a non-empty error message.');

        $this->assertNoSessionWasOpened($client);
    }

    /**
     * Review finding C1 — a Referer that is not an absolute URL names no origin. It cannot be
     * matched against the allow-list, so it must be refused instead of falling through the chain.
     */
    #[DataProvider('unparseableReferers')]
    public function testStateChangingRequestWithAnUnparseableRefererIsRefused(string $referer): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_REFERER' => $referer],
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            sprintf('A Referer of "%s" names no origin and must be refused.', $referer),
        );

        $body = self::jsonBody($client);
        self::assertIsString($body['error'] ?? null, 'The refusal must answer the repo\'s {"error": ...} JSON body.');
        self::assertNotSame('', $body['error'], 'The refusal must carry a non-empty error message.');

        $this->assertNoSessionWasOpened($client);
    }

    /** @return iterable<string, array{string}> */
    public static function unparseableReferers(): iterable
    {
        yield 'not a URL' => ['not-a-url'];
        yield 'relative path' => ['/relative/page'];
    }

    /**
     * Review finding T1 — the Referer fallback must keep first-party traffic working: a page of
     * the configured storefront is an allowed origin, so the login behind it must be served.
     */
    public function testStateChangingRequestWithTheStorefrontRefererIsAllowed(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_REFERER' => self::configuredStorefrontOrigin().'/some/page?x=1'],
        );

        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            'A Referer on the configured storefront origin is first-party and must be allowed.',
        );

        self::assertCount(1, $this->emittedSessionCookieNames($client), 'An allowed Referer must still let the login complete.');
    }

    /**
     * Fetch metadata is the only site signal left when a browser sends neither Origin nor
     * Referer; declaring the request cross-site is an announcement that cannot be ignored.
     * The value is matched case-insensitively, as the header's grammar is case-insensitive.
     */
    #[DataProvider('crossSiteFetchMetadata')]
    public function testStateChangingRequestWithCrossSiteFetchMetadataIsRefused(string $fetchSite): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_SEC_FETCH_SITE' => $fetchSite],
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            'A state-changing request declared cross-site by its fetch metadata must be refused.',
        );

        $body = self::jsonBody($client);
        self::assertIsString($body['error'] ?? null, 'The refusal must answer the repo\'s {"error": ...} JSON body.');
        self::assertNotSame('', $body['error'], 'The refusal must carry a non-empty error message.');

        $this->assertNoSessionWasOpened($client);
    }

    /** @return iterable<string, array{string}> */
    public static function crossSiteFetchMetadata(): iterable
    {
        yield 'lowercase' => ['cross-site'];
        yield 'uppercase' => ['CROSS-SITE'];
    }

    /**
     * S6 — curl, a provider webhook and every server-to-server client send none of the three
     * signals. Refusing them would break the provider callbacks and the whole existing suite, so
     * a request that announces no site at all is not cross-site.
     */
    public function testStateChangingRequestWithoutAnyOriginSignalIsAllowed(): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        // No Origin, no Referer and no Sec-Fetch-Site: the request carries nothing to judge.
        $client->jsonRequest('POST', '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD]);

        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            'A state-changing request that announces no site must not be refused as cross-site.',
        );

        self::assertCount(1, $this->emittedSessionCookieNames($client), 'A signal-less login must still authenticate the caller.');
    }

    /**
     * S5 — only the `cross-site` value of the fetch metadata is a refusal: `same-origin`,
     * `same-site` and `none` are the announcements a browser makes for first-party, sibling-site
     * and user-initiated requests, and none of them is cross-site.
     */
    #[DataProvider('nonCrossSiteFetchMetadata')]
    public function testFetchMetadataValuesOtherThanCrossSiteAreNotRefused(string $fetchSite): void
    {
        $client = static::createClient();
        $email = $this->createCustomerUser();

        $client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => self::PASSWORD],
            ['HTTP_SEC_FETCH_SITE' => $fetchSite],
        );

        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            sprintf('Sec-Fetch-Site "%s" must not be treated as cross-site.', $fetchSite),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function nonCrossSiteFetchMetadata(): iterable
    {
        yield 'same-origin' => ['same-origin'];
        yield 'same-site' => ['same-site'];
        yield 'none' => ['none'];
    }

    /**
     * S1 — the guard only watches state-changing methods. A read carries no state to forge, and
     * CORS is what keeps a foreign origin from reading the answer, so the request is served.
     */
    public function testReadRequestsFromAForeignOriginAreNotRefused(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/storefront/products', [], [], ['HTTP_ORIGIN' => self::FOREIGN_ORIGIN]);

        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            'A GET from a foreign origin must not be refused by the CSRF guard.',
        );

        $client->request('HEAD', '/api/v1/storefront/products', [], [], ['HTTP_ORIGIN' => self::FOREIGN_ORIGIN]);

        // Symfony routes HEAD onto the GET route and the kernel drops the body, so a served HEAD
        // is 200 like its GET: anything else would mean the guard, not the router, answered.
        self::assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            'HEAD changes no state and must never be refused as cross-site.',
        );
    }

    /**
     * The CORS preflight is answered by CorsSubscriber above the guard, and must stay that way:
     * a browser whose preflight is refused never sends the real request at all, so the guard
     * would turn every allowed cross-origin call into a CORS failure.
     */
    public function testPreflightFromAForeignOriginIsStillAnsweredByCors(): void
    {
        $client = static::createClient();

        $client->request('OPTIONS', '/api/v1/auth/login', [], [], ['HTTP_ORIGIN' => self::FOREIGN_ORIGIN]);

        $response = $client->getResponse();

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $response->getStatusCode(),
            'The OPTIONS preflight must still be answered by CorsSubscriber.',
        );
        self::assertStringContainsString(
            'Origin',
            (string) $response->headers->get('Vary'),
            'A preflight answer depends on the Origin header, so the response must vary by it.',
        );
        self::assertNotNull(
            $response->headers->get('Access-Control-Allow-Methods'),
            'The preflight must announce the methods the browser may use.',
        );
        self::assertNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'A foreign origin is never echoed back, preflight or not.',
        );
    }

    /**
     * S1 — the guard is scoped to /api/v1. A state-changing request elsewhere belongs to the
     * router and the controllers, so the guard must leave its answer untouched.
     */
    public function testStateChangingRequestOutsideTheApiPrefixIsNotRefused(): void
    {
        $client = static::createClient();

        $client->request('POST', '/healthz', [], [], ['HTTP_ORIGIN' => self::FOREIGN_ORIGIN]);

        self::assertSame(
            Response::HTTP_METHOD_NOT_ALLOWED,
            $client->getResponse()->getStatusCode(),
            'A POST outside /api/v1 must be answered by the router (GET-only route), never by the guard.',
        );
    }

    /**
     * Review finding T2 — a forged write is not only a POST: the guard's method set must cover
     * every state-changing method the router exposes, so a foreign origin cannot pick another
     * verb to slip past it.
     */
    #[DataProvider('stateChangingMethods')]
    public function testStateChangingMethodsFromAForeignOriginAreRefused(string $method, string $path): void
    {
        $client = static::createClient();

        $client->jsonRequest($method, $path, [], ['HTTP_ORIGIN' => self::FOREIGN_ORIGIN]);

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            sprintf('%s %s writes state, so a foreign origin must be refused.', $method, $path),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function stateChangingMethods(): iterable
    {
        yield 'PUT profile' => ['PUT', '/api/v1/storefront/customer/profile'];
        yield 'PATCH cart item' => ['PATCH', '/api/v1/storefront/cart/items/0f8fad5b-d9cb-469f-a165-70867728950e'];
        yield 'DELETE cart' => ['DELETE', '/api/v1/storefront/cart'];
    }

    /**
     * The end-to-end refusal: a logged-in session is exactly the credential a cross-site request
     * would borrow, so the origin is the only thing separating the two. The checkout must never
     * start, which means no order, no payment and no stock movement.
     */
    public function testOrderCreationFromAForeignOriginIsRefused(): void
    {
        $client = static::createClient();
        $this->resetRateLimiter();

        $connection = self::connection();

        $customer = $this->createOrderCustomer();
        $slug = $this->seedProduct($connection);
        $client->loginUser($customer);

        $client->jsonRequest('POST', '/api/v1/storefront/orders', self::orderPayload($slug), [
            'HTTP_ORIGIN' => self::FOREIGN_ORIGIN,
            'HTTP_IDEMPOTENCY_KEY' => $this->idempotencyKey('csrf-foreign-origin'),
        ]);

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            'An order placed from an origin outside the allow-list must be refused.',
        );

        $body = self::jsonBody($client);
        self::assertIsString($body['error'] ?? null, 'The refusal must answer the repo\'s {"error": ...} JSON body.');
        self::assertNotSame('', $body['error'], 'The refusal must carry a non-empty error message.');

        self::assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM ordering_orders WHERE customer_id = :id',
                ['id' => $customer->getId()],
            ),
            'A refused checkout must not create an order.',
        );
        self::assertSame(5, $this->stock($connection, $slug), 'A refused checkout must not move stock.');
    }

    /**
     * The allowed counterpart of the previous pin: the configured storefront origin is the
     * allow-list, so the same checkout must complete and record exactly one order.
     */
    public function testOrderCreationFromTheStorefrontOriginSucceeds(): void
    {
        $client = static::createClient();
        $this->resetRateLimiter();

        $origin = self::configuredStorefrontOrigin();
        $connection = self::connection();

        $customer = $this->createOrderCustomer();
        $slug = $this->seedProduct($connection);
        $client->loginUser($customer);

        $client->jsonRequest('POST', '/api/v1/storefront/orders', self::orderPayload($slug), [
            'HTTP_ORIGIN' => $origin,
            'HTTP_IDEMPOTENCY_KEY' => $this->idempotencyKey('csrf-storefront-origin'),
        ]);

        self::assertSame(
            Response::HTTP_CREATED,
            $client->getResponse()->getStatusCode(),
            'The configured storefront origin is the allow-list: its checkout must go through.',
        );

        self::assertSame(
            1,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM ordering_orders WHERE customer_id = :id',
                ['id' => $customer->getId()],
            ),
            'An allowed checkout must record exactly one order.',
        );
    }

    /**
     * @return array{lines: list<array{slug: string, quantity: int}>, deliveryMethod: string, paymentMethod: string}
     */
    private static function orderPayload(string $slug): array
    {
        return [
            'lines' => [['slug' => $slug, 'quantity' => 1]],
            'deliveryMethod' => 'home',
            'paymentMethod' => 'Carte bancaire',
        ];
    }

    /**
     * The allow-list is deployment configuration, so the origin is read from the same env var the
     * application reads (tests/bootstrap.php boots Dotenv). Pinning the literal would agree with a
     * misconfigured deployment instead of with its configuration.
     */
    private static function configuredStorefrontOrigin(): string
    {
        $origin = $_SERVER['STOREFRONT_CORS_ORIGIN'] ?? null;
        self::assertIsString($origin, 'The booted environment must define STOREFRONT_CORS_ORIGIN.');
        self::assertNotSame('', $origin, 'The configured STOREFRONT_CORS_ORIGIN must name an origin.');

        return $origin;
    }

    private static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }

    /**
     * TDD-005 — the order limiter is keyed by the customer id, so consecutive runs of this class
     * would share one bucket. The pool is cleared after the kernel exists, because WebTestCase
     * forbids booting it before createClient(); this mirrors CheckoutStockReservationTest.
     */
    private function resetRateLimiter(): void
    {
        $container = static::getContainer();
        if ($container->has('cache.rate_limiter')) {
            /** @var CacheItemPoolInterface $pool */
            $pool = $container->get('cache.rate_limiter');
            $pool->clear();
        }
    }

    /**
     * A key that remembers a real checkout, so that tearDown can remove the row the handler wrote
     * for it: an idempotency record outliving the order it announced would poison a later run.
     */
    private function idempotencyKey(string $prefix): string
    {
        $key = $prefix.'-'.Uuid::v4()->toRfc4122();
        $this->usedIdempotencyKeys[] = $key;

        return $key;
    }

    private function seedProduct(Connection $connection): string
    {
        $slug = 'test-csrf-origin-'.substr(Uuid::v4()->toRfc4122(), 0, 8);

        $connection->executeStatement(
            'INSERT INTO storefront_product_view (
                id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                stock, stock_quantity, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            )
            SELECT :id, :slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
                   stock, 5, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants, approval_status, published
            FROM storefront_product_view
            WHERE slug = :source',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'slug' => $slug,
                'source' => 'baignoire-d-code',
            ],
        );

        $this->createdProductSlugs[] = $slug;

        return $slug;
    }

    private function stock(Connection $connection, string $slug): int
    {
        return (int) $connection->fetchOne(
            'SELECT stock_quantity FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    private function createOrderCustomer(): CustomerUser
    {
        $connection = self::connection();

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('csrf.order.%s@example.com', substr($id, 0, 8));
        $this->insertCustomer($connection, $id, $email, 'CSRF Order User');

        return new CustomerUser($id, $email, null, 'ROLE_USER', 'active');
    }

    /**
     * The order endpoint records its Idempotency-Key so a retry is answered once. The row belongs
     * to this test, so it is removed here instead of accumulating in the shared database.
     */
    private function deleteProcessedCommands(Connection $connection): void
    {
        if ([] === $this->usedIdempotencyKeys) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM processed_commands WHERE key IN (:keys)',
            ['keys' => $this->usedIdempotencyKeys],
            ['keys' => ArrayParameterType::STRING],
        );
        $this->usedIdempotencyKeys = [];
    }

    /**
     * A checkout writes an order, its lines and audit trail, a stock reservation, a pending
     * payment with its ledger row and the outbox events announcing them. Everything is found
     * through the customers seeded here, so a pin that unexpectedly lets a foreign origin
     * through still cleans up after itself.
     */
    private function deleteCreatedOrders(Connection $connection): void
    {
        if ([] === $this->createdUserIds) {
            return;
        }

        /** @var list<string> $orderIds */
        $orderIds = $connection->fetchFirstColumn(
            'SELECT id FROM ordering_orders WHERE customer_id IN (:customerIds)',
            ['customerIds' => $this->createdUserIds],
            ['customerIds' => ArrayParameterType::STRING],
        );

        if ([] === $orderIds) {
            return;
        }

        /** @var list<string> $paymentIds */
        $paymentIds = $connection->fetchFirstColumn(
            'SELECT id FROM payment WHERE order_id IN (:orderIds)',
            ['orderIds' => $orderIds],
            ['orderIds' => ArrayParameterType::STRING],
        );

        $connection->executeStatement(
            'DELETE FROM outbox WHERE aggregate_id IN (:aggregateIds)',
            ['aggregateIds' => array_merge($orderIds, $paymentIds)],
            ['aggregateIds' => ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM payment_ledger WHERE payment_id IN (:paymentIds)',
            ['paymentIds' => $paymentIds],
            ['paymentIds' => ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM payment WHERE order_id IN (:orderIds)',
            ['orderIds' => $orderIds],
            ['orderIds' => ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM inventory_reservation WHERE order_id IN (:orderIds)',
            ['orderIds' => $orderIds],
            ['orderIds' => ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM ordering_order_lines WHERE order_id IN (:orderIds)',
            ['orderIds' => $orderIds],
            ['orderIds' => ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM ordering_order_audit_trail WHERE order_id IN (:orderIds)',
            ['orderIds' => $orderIds],
            ['orderIds' => ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM ordering_orders WHERE id IN (:orderIds)',
            ['orderIds' => $orderIds],
            ['orderIds' => ArrayParameterType::STRING],
        );
    }

    /**
     * @return list<string>
     */
    private function emittedSessionCookieNames(KernelBrowser $client): array
    {
        $names = array_map(
            static fn (ResponseCookie $cookie): string => $cookie->getName(),
            $client->getResponse()->headers->getCookies(),
        );

        return array_values(array_intersect($names, self::SESSION_COOKIE_NAMES));
    }

    /**
     * A refused login must not open a session. The test environment swaps the session storage for
     * a mock, so both cookie names are checked: asserting only PHPSESSID would be vacuous here.
     */
    private function assertNoSessionWasOpened(KernelBrowser $client): void
    {
        self::assertSame([], $this->emittedSessionCookieNames($client), 'A refused cross-site login must not set a session cookie.');
        self::assertNull($client->getCookieJar()->get(self::SESSION_COOKIE_NAMES[0]), 'A refused cross-site login must not store a session id.');
        self::assertNull($client->getCookieJar()->get(self::SESSION_COOKIE_NAMES[1]), 'A refused cross-site login must not store a session id.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function jsonBody(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function createCustomerUser(): string
    {
        $connection = self::connection();

        $id = Uuid::v4()->toRfc4122();
        $email = sprintf('csrf.origin.%s@example.com', substr($id, 0, 8));
        $this->insertCustomer($connection, $id, $email, 'CSRF Origin User');

        return $email;
    }

    private function insertCustomer(Connection $connection, string $id, string $email, string $name): void
    {
        $connection->insert('customer_profile', [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => 'ROLE_USER',
            'status' => 'active',
        ]);

        $this->createdUserIds[] = $id;
    }
}
