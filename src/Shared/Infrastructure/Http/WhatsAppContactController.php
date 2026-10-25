<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Query\GetWhatsAppContact;
use App\Shared\Domain\WhatsApp\InvalidContextualMessageException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;

final class WhatsAppContactController
{
    public function __construct(private MessengerQuery $queries)
    {
    }

    #[Route('/api/v1/storefront/contact/whatsapp', name: 'storefront_whatsapp_contact', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // R11 — only a scalar `message` is a contextual message. An array (`?message[]=…`) is the
        // caller's mistake, so it is refused here rather than silently read as absent (ERR-001).
        $message = $request->query->all()['message'] ?? null;

        if (null !== $message && !is_string($message)) {
            return new JsonResponse(
                ['error' => 'The message parameter must be a single string value.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            /** @var array<string, string> $data */
            $data = $this->queries->dispatch(new GetWhatsAppContact($message));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();

            // R12 — an unusable contextual message is the caller's mistake: 400 with the project's
            // {"error": …} body. A broken configuration or a missing handler keeps its 5xx status.
            if ($previous instanceof InvalidContextualMessageException) {
                return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            throw $e;
        }

        return new JsonResponse($data);
    }
}
