<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\SubmitContactMessage;
use App\Shared\Infrastructure\Http\RateLimitChecker;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/storefront/contact')]
final class ContactController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        #[Target('contact_submission')]
        private RateLimiterFactoryInterface $contactLimiter,
        private RateLimitChecker $rateLimitChecker,
    ) {
    }

    #[Route('', name: 'storefront_contact_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        if ($limitResponse = $this->rateLimitChecker->check($this->contactLimiter, $request)) {
            return $limitResponse;
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        foreach (['name', 'email', 'message'] as $field) {
            if (!is_string($body[$field] ?? null) || '' === trim($body[$field])) {
                return new JsonResponse(['error' => $field.' required'], Response::HTTP_BAD_REQUEST);
            }
        }

        $email = trim((string) $body['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Invalid email address'], Response::HTTP_BAD_REQUEST);
        }

        $id = Uuid::v7()->toRfc4122();
        $this->commandBus->dispatch(new SubmitContactMessage(
            $id,
            trim((string) $body['name']),
            $email,
            is_string($body['phone'] ?? null) && '' !== trim((string) $body['phone']) ? trim((string) $body['phone']) : null,
            is_string($body['subject'] ?? null) && '' !== trim((string) $body['subject']) ? trim((string) $body['subject']) : null,
            trim((string) $body['message']),
        ));

        return new JsonResponse(['id' => $id, 'status' => 'received'], Response::HTTP_CREATED);
    }
}
