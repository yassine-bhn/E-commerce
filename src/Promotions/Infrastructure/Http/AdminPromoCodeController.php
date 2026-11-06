<?php

declare(strict_types=1);

namespace App\Promotions\Infrastructure\Http;

use App\Promotions\Application\Command\CreatePromoCode;
use App\Promotions\Application\Command\DeletePromoCode;
use App\Promotions\Application\Command\UpdatePromoCode;
use App\Promotions\Application\Query\GetPromoCode;
use App\Promotions\Application\Query\ListAdminPromoCodes;
use App\Promotions\Domain\Model\PromoCodeAlreadyExistsException;
use App\Promotions\Domain\Model\PromoCodeNotFoundException;
use App\Shared\Application\Pagination\Page;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office management of promo codes. Discounts are integer basis points
 * (1000 = 10 %), never floats.
 *
 * solid Task 4.10 — every write is one command on `command.bus`; the edge keeps the request-shape
 * validation (JSON, code format, basis-point range) and maps the domain's answer to HTTP.
 */
#[Route('/api/v1/admin/promotions/promo-codes')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPromoCodeController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
    ) {
    }

    /**
     * R12: one bounded page of the promo-code list, filtered in SQL.
     */
    #[Route('', name: 'admin_promo_codes_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var Page $page */
        $page = $this->queries->dispatch(new ListAdminPromoCodes(
            page: ListQueryParams::page($request, ListAdminPromoCodes::DEFAULT_SIZE, ListAdminPromoCodes::MAX_SIZE),
            q: ListQueryParams::text($request),
            active: ListQueryParams::boolOrNull($request, 'active'),
        ));

        return new JsonResponse($page->toArray());
    }

    #[Route('', name: 'admin_promo_codes_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $code = is_string($body['code'] ?? null) ? strtoupper(trim($body['code'])) : '';
        if (1 !== preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            return new JsonResponse(['error' => 'The code must contain 3 to 32 letters, digits, "-" or "_".'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $basisPoints = $this->basisPoints($body['discountBasisPoints'] ?? null);
        if (null === $basisPoints) {
            return new JsonResponse(['error' => 'discountBasisPoints must be an integer between 1 and 10000.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $active = !array_key_exists('active', $body) || true === $body['active'];

        try {
            $this->commandBus->dispatch(new CreatePromoCode($code, $basisPoints, $active));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
        }

        return new JsonResponse(['code' => $code, 'discountBasisPoints' => $basisPoints, 'active' => $active], Response::HTTP_CREATED);
    }

    #[Route('/{code}', name: 'admin_promo_codes_update', methods: ['PUT'])]
    public function update(string $code, Request $request): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $code = strtoupper($code);

        $basisPoints = null;
        if (array_key_exists('discountBasisPoints', $body)) {
            $basisPoints = $this->basisPoints($body['discountBasisPoints']);
            if (null === $basisPoints) {
                return new JsonResponse(['error' => 'discountBasisPoints must be an integer between 1 and 10000.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $active = null;
        if (array_key_exists('active', $body)) {
            if (!is_bool($body['active'])) {
                return new JsonResponse(['error' => 'active must be a boolean.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $active = $body['active'];
        }

        try {
            $this->commandBus->dispatch(new UpdatePromoCode($code, $basisPoints, $active));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
        }

        $row = $this->promoCode($code);
        if (null === $row) {
            return new JsonResponse(['error' => 'Promo code not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['code' => $code, 'discountBasisPoints' => $row['discountBasisPoints'], 'active' => $row['active']]);
    }

    #[Route('/{code}', name: 'admin_promo_codes_delete', methods: ['DELETE'])]
    public function delete(string $code): JsonResponse
    {
        $code = strtoupper($code);

        try {
            $this->commandBus->dispatch(new DeletePromoCode($code));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
        }

        return new JsonResponse(['success' => true, 'code' => $code]);
    }

    /** @return array{code: string, discountBasisPoints: int, active: bool}|null */
    private function promoCode(string $code): ?array
    {
        /* @var array{code: string, discountBasisPoints: int, active: bool}|null */
        return $this->queries->dispatch(new GetPromoCode($code));
    }

    private function rootCause(HandlerFailedException $exception): \Throwable
    {
        $cause = $exception;

        while ($cause instanceof HandlerFailedException && null !== $cause->getPrevious()) {
            $cause = $cause->getPrevious();
        }

        return $cause;
    }

    private function domainFailure(\Throwable $error): JsonResponse
    {
        if ($error instanceof PromoCodeAlreadyExistsException) {
            return new JsonResponse(['error' => 'This promo code already exists.'], Response::HTTP_CONFLICT);
        }

        if ($error instanceof PromoCodeNotFoundException) {
            return new JsonResponse(['error' => 'Promo code not found.'], Response::HTTP_NOT_FOUND);
        }

        throw $error;
    }

    /** @return array<string, mixed>|null */
    private function decode(Request $request): ?array
    {
        $body = json_decode($request->getContent(), true);

        /** @var array<string, mixed>|null $result */
        $result = is_array($body) ? $body : null;

        return $result;
    }

    private function basisPoints(mixed $value): ?int
    {
        if (!is_int($value) || $value < 1 || $value > 10000) {
            return null;
        }

        return $value;
    }
}
