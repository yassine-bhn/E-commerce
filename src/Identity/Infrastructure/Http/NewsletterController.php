<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\SubscribeNewsletter;
use App\Shared\Infrastructure\Http\RateLimitChecker;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/storefront/newsletter')]
final class NewsletterController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        #[Target('contact_submission')]
        private RateLimiterFactoryInterface $contactLimiter,
        private RateLimitChecker $rateLimitChecker,
    ) {
    }

    #[Route('', name: 'storefront_newsletter_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        if ($limitResponse = $this->rateLimitChecker->check($this->contactLimiter, $request)) {
            return $limitResponse;
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Invalid email address'], Response::HTTP_BAD_REQUEST);
        }

        $id = Uuid::v7()->toRfc4122();
        $this->commandBus->dispatch(new SubscribeNewsletter($id, $email));

        return new JsonResponse(['status' => 'subscribed'], Response::HTTP_CREATED);
    }
}
