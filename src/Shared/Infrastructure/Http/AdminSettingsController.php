<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Command\UpdatePlatformSettings;
use App\Shared\Application\Command\UpdateShippingPolicy;
use App\Shared\Application\Query\GetPlatformSettings;
use App\Shared\Infrastructure\ReadModel\PlatformSettingsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/admin/settings')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSettingsController
{
    /** Mirrors the VARCHAR(32) columns in migration 20261022000004. */
    private const int MAX_CONTACT_LENGTH = 32;

    /** Mirrors the VARCHAR(64) payment.method column in migration 20261022000006. */
    private const int MAX_METHOD_LENGTH = 64;

    public function __construct(
        private MessengerQuery $queries,
        private MessageBusInterface $commandBus,
        private PlatformSettingsRepository $settingsRepository,
    ) {
    }

    #[Route('', name: 'admin_settings_read', methods: ['GET'])]
    public function read(): JsonResponse
    {
        return new JsonResponse($this->settings());
    }

    /**
     * I-16 — PUT replaces the whole singleton: contact channels and the pending-order window.
     * Missing or mistyped fields are a malformed body (400); out-of-range values are a semantic
     * rejection (422) so the operator sees the difference between "bad request" and "bad value".
     */
    #[Route('', name: 'admin_settings_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $contactPhone = $body['contactPhone'] ?? null;
        $contactWhatsapp = $body['contactWhatsapp'] ?? null;
        $autoCancelMinutes = $body['autoCancelMinutes'] ?? null;

        if (!is_string($contactPhone) || !is_string($contactWhatsapp) || !is_int($autoCancelMinutes)) {
            return new JsonResponse(
                ['error' => 'contactPhone and contactWhatsapp (strings) and autoCancelMinutes (integer) are required'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $contactPhone = trim($contactPhone);
        $contactWhatsapp = trim($contactWhatsapp);

        if (mb_strlen($contactPhone) > self::MAX_CONTACT_LENGTH || mb_strlen($contactWhatsapp) > self::MAX_CONTACT_LENGTH) {
            return new JsonResponse(
                ['error' => sprintf('Contact channels must be at most %d characters', self::MAX_CONTACT_LENGTH)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($autoCancelMinutes < 1) {
            return new JsonResponse(['error' => 'autoCancelMinutes must be a positive integer'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // O-19 — the allow-list is optional on update so an older client that does not know about
        // it keeps the configured list instead of silently resetting checkout to the defaults.
        $paymentMethods = $this->settingsRepository->paymentMethods();
        if (array_key_exists('paymentMethods', $body)) {
            $validated = $this->validatePaymentMethods($body['paymentMethods']);
            if (null === $validated) {
                return new JsonResponse(
                    ['error' => sprintf('paymentMethods must be a non-empty list of unique strings, at most %d characters each', self::MAX_METHOD_LENGTH)],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $paymentMethods = $validated;
        }

        $this->commandBus->dispatch(new UpdatePlatformSettings($contactPhone, $contactWhatsapp, $autoCancelMinutes, $paymentMethods));

        return new JsonResponse($this->settings());
    }

    /**
     * I-16 — delivery is priced from operator data: fee in minor units plus the subtotal at which
     * delivery becomes free. Negative amounts are a semantic rejection (422).
     */
    #[Route('/shipping', name: 'admin_settings_shipping_update', methods: ['PUT'])]
    public function updateShipping(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $deliveryFee = $body['deliveryFee'] ?? null;
        $freeDeliveryThreshold = $body['freeDeliveryThreshold'] ?? null;

        if (!is_int($deliveryFee) || !is_int($freeDeliveryThreshold)) {
            return new JsonResponse(
                ['error' => 'deliveryFee and freeDeliveryThreshold (integers, minor units) are required'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($deliveryFee < 0 || $freeDeliveryThreshold < 0) {
            return new JsonResponse(
                ['error' => 'deliveryFee and freeDeliveryThreshold must be zero or positive'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->commandBus->dispatch(new UpdateShippingPolicy($deliveryFee, $freeDeliveryThreshold));

        return new JsonResponse(['deliveryFee' => $deliveryFee, 'freeDeliveryThreshold' => $freeDeliveryThreshold]);
    }

    /** @return array{contactPhone: string, contactWhatsapp: string, autoCancelMinutes: int, paymentMethods: list<string>} */
    private function settings(): array
    {
        /** @var array{contactPhone: string, contactWhatsapp: string, autoCancelMinutes: int, paymentMethods: list<string>} $settings */
        $settings = $this->queries->dispatch(new GetPlatformSettings());

        return $settings;
    }

    /** @return list<string>|null null when the payload is not a usable allow-list */
    private function validatePaymentMethods(mixed $raw): ?array
    {
        if (!is_array($raw) || [] === $raw) {
            return null;
        }

        $methods = [];
        foreach ($raw as $method) {
            if (!is_string($method)) {
                return null;
            }

            $method = trim($method);
            if ('' === $method || mb_strlen($method) > self::MAX_METHOD_LENGTH || in_array($method, $methods, true)) {
                return null;
            }

            $methods[] = $method;
        }

        return $methods;
    }
}
