<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\DeleteCustomerAddress;
use App\Identity\Application\Command\SaveCustomerAddress;
use App\Identity\Application\Command\UpdateCustomerProfile;
use App\Identity\Application\Query\GetCustomerProfile;
use App\Identity\Application\Query\ListCustomerAddresses;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/storefront/customer')]
final class CustomerController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
    ) {
    }

    #[Route('/profile', name: 'storefront_customer_profile_get', methods: ['GET'])]
    public function getProfile(Request $request): JsonResponse
    {
        $customerId = $request->headers->get('X-Customer-Id');
        if (!is_string($customerId) || !Uuid::isValid($customerId)) {
            return new JsonResponse(['error' => 'Valid X-Customer-Id header required'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed>|null $profile */
        $profile = $this->queries->dispatch(new GetCustomerProfile($customerId));

        return new JsonResponse($profile ?? ['id' => $customerId, 'name' => '', 'email' => '', 'phone' => '', 'type' => 'particulier']);
    }

    #[Route('/profile', name: 'storefront_customer_profile_update', methods: ['PUT', 'POST'])]
    public function updateProfile(Request $request): JsonResponse
    {
        $customerId = $request->headers->get('X-Customer-Id');
        if (!is_string($customerId) || !Uuid::isValid($customerId)) {
            return new JsonResponse(['error' => 'Valid X-Customer-Id header required'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $phone = is_string($body['phone'] ?? null) ? trim($body['phone']) : '';
        $type = in_array($body['type'] ?? '', ['particulier', 'pro'], true) ? (string) $body['type'] : 'particulier';
        $company = is_string($body['company'] ?? null) && '' !== trim($body['company']) ? trim($body['company']) : null;
        $mf = is_string($body['mf'] ?? null) && '' !== trim($body['mf']) ? trim($body['mf']) : null;

        $this->commandBus->dispatch(new UpdateCustomerProfile(
            $customerId,
            $name,
            $email,
            $phone,
            $type,
            $company,
            $mf,
        ));

        return new JsonResponse(['status' => 'updated']);
    }

    #[Route('/addresses', name: 'storefront_customer_addresses_list', methods: ['GET'])]
    public function listAddresses(Request $request): JsonResponse
    {
        $customerId = $request->headers->get('X-Customer-Id');
        if (!is_string($customerId) || !Uuid::isValid($customerId)) {
            return new JsonResponse(['error' => 'Valid X-Customer-Id header required'], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<array<string, mixed>> $addresses */
        $addresses = $this->queries->dispatch(new ListCustomerAddresses($customerId));

        return new JsonResponse(['items' => $addresses]);
    }

    #[Route('/addresses', name: 'storefront_customer_addresses_save', methods: ['POST'])]
    public function saveAddress(Request $request): JsonResponse
    {
        $customerId = $request->headers->get('X-Customer-Id');
        if (!is_string($customerId) || !Uuid::isValid($customerId)) {
            return new JsonResponse(['error' => 'Valid X-Customer-Id header required'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $id = is_string($body['id'] ?? null) && Uuid::isValid($body['id']) ? $body['id'] : Uuid::v7()->toRfc4122();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $phone = is_string($body['phone'] ?? null) ? trim($body['phone']) : '';
        $street = is_string($body['street'] ?? null) ? trim($body['street']) : '';
        $city = is_string($body['city'] ?? null) ? trim($body['city']) : '';
        $gov = is_string($body['governorate'] ?? null) ? trim($body['governorate']) : '';
        $zip = is_string($body['zip'] ?? null) ? trim($body['zip']) : '';

        if ('' === $name || '' === $street || '' === $city) {
            return new JsonResponse(['error' => 'name, street, and city are required'], Response::HTTP_BAD_REQUEST);
        }

        $this->commandBus->dispatch(new SaveCustomerAddress(
            $id,
            $customerId,
            $name,
            $phone,
            $street,
            $city,
            $gov,
            $zip,
        ));

        return new JsonResponse([
            'id' => $id,
            'name' => $name,
            'phone' => $phone,
            'street' => $street,
            'city' => $city,
            'governorate' => $gov,
            'zip' => $zip,
        ], Response::HTTP_CREATED);
    }

    #[Route('/addresses/{id}', name: 'storefront_customer_addresses_delete', methods: ['DELETE'])]
    public function deleteAddress(string $id, Request $request): JsonResponse
    {
        $customerId = $request->headers->get('X-Customer-Id');
        if (!is_string($customerId) || !Uuid::isValid($customerId)) {
            return new JsonResponse(['error' => 'Valid X-Customer-Id header required'], Response::HTTP_BAD_REQUEST);
        }

        $this->commandBus->dispatch(new DeleteCustomerAddress($id, $customerId));

        return new JsonResponse(['status' => 'deleted']);
    }
}
