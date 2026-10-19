<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Infrastructure\Security\CustomerUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class StorefrontApiTest extends WebTestCase
{
    public function testStorefrontReferenceEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/reference');

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('categories', $data);
        self::assertArrayHasKey('brands', $data);
        self::assertArrayHasKey('governorates', $data);
        self::assertArrayHasKey('shipping', $data);
    }

    public function testStorefrontProductsListAndSearch(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/products');

        self::assertResponseIsSuccessful();
        /** @var array{items: list<array<string, mixed>>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['items']);

        $firstSlug = (string) $data['items'][0]['slug'];

        $client->request('GET', '/api/v1/storefront/products/'.$firstSlug);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/storefront/products/search?q=baignoire');
        self::assertResponseIsSuccessful();
    }

    public function testStorefrontCartQuote(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/storefront/cart/quote',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'lines' => [['slug' => 'baignoire-d-code', 'qty' => 1]],
                'deliveryMethod' => 'home',
                'promoCode' => 'ZIDEN10',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $quote */
        $quote = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $quote['count']);
        self::assertSame(680, $quote['subtotal']);
        self::assertSame(68, $quote['discount']);
        self::assertSame(627, $quote['total']);
    }

    public function testStorefrontReviewsFlow(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/storefront/products/baignoire-d-code/reviews');
        self::assertResponseIsSuccessful();

        $client->request(
            'POST',
            '/api/v1/storefront/products/baignoire-d-code/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'author' => 'Test Reviewer',
                'rating' => 5,
                'comment' => 'Excellent produit test.',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testStorefrontContactAndNewsletter(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/storefront/contact',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Client Test',
                'email' => 'client@test.tn',
                'phone' => '12345678',
                'message' => 'Message de test pour contact.',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request(
            'POST',
            '/api/v1/storefront/newsletter',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'newsletter_test@domain.tn'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testPromoCodeValidation(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/storefront/promotions/validate?code=ZIDEN10');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['valid']);
        self::assertSame(10, $data['discountPercent']);

        $client->request('GET', '/api/v1/storefront/promotions/validate?code=INVALID_CODE');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $invalid */
        $invalid = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($invalid['valid']);
    }

    public function testCustomerProfileAndAddresses(): void
    {
        $client = static::createClient();
        $customerId = '01929312-7000-7000-8000-000000000001';
        $user = new CustomerUser($customerId, 'm.zidane@example.com', null, 'ROLE_USER', 'active');
        $client->loginUser($user);

        // Update Profile
        $client->request(
            'PUT',
            '/api/v1/storefront/customer/profile',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Mohamed Zidane',
                'email' => 'm.zidane@example.com',
                'phone' => '+21622334455',
                'type' => 'pro',
                'company' => 'Zidane Groupe',
                'mf' => '1234567/A',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        // Get Profile
        $client->request('GET', '/api/v1/storefront/customer/profile');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $profile */
        $profile = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Mohamed Zidane', $profile['name']);

        // Save Address
        $client->request(
            'POST',
            '/api/v1/storefront/customer/addresses',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Showroom Tunis',
                'phone' => '+21622334455',
                'street' => 'Route de la Marsa',
                'city' => 'Tunis',
                'governorate' => 'Tunis',
                'zip' => '1000',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        /** @var array<string, mixed> $addr */
        $addr = json_decode((string) $client->getResponse()->getContent(), true);
        $addressId = (string) $addr['id'];

        // List Addresses
        $client->request('GET', '/api/v1/storefront/customer/addresses');
        self::assertResponseIsSuccessful();

        // Delete Address
        $client->request('DELETE', '/api/v1/storefront/customer/addresses/'.$addressId);
        self::assertResponseIsSuccessful();
    }

    public function testOrderCreationAndTracking(): void
    {
        $client = static::createClient();
        $customerId = '01929312-7000-7000-8000-000000000001';
        $user = new CustomerUser($customerId, 'm.zidane@example.com', null, 'ROLE_USER', 'active');
        $client->loginUser($user);

        // Place Order
        $client->request(
            'POST',
            '/api/v1/storefront/orders',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'lines' => [['slug' => 'baignoire-d-code', 'qty' => 1]],
                'deliveryMethod' => 'home',
                'paymentMethod' => 'Carte bancaire',
                'promoCode' => 'ZIDEN10',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        /** @var array<string, mixed> $created */
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        $orderNumber = (string) $created['orderNumber'];

        // Track Order
        $client->request('GET', '/api/v1/storefront/orders/track/'.$orderNumber);
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $order */
        $order = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($orderNumber, $order['id']);
        self::assertSame('En préparation', $order['status']);

        // List Orders for customer
        $client->request('GET', '/api/v1/storefront/orders');
        self::assertResponseIsSuccessful();
    }
}
