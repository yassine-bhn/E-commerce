<?php

declare(strict_types=1);

namespace App\Promotions\Infrastructure\Http;

use App\Promotions\Application\Query\ListAdminPromoCodes;
use App\Shared\Application\Pagination\Page;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Super-admin management of promo codes. Discounts are integer basis points
 * (1000 = 10 %), never floats.
 */
#[Route('/api/v1/admin/promotions/promo-codes')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class AdminPromoCodeController
{
    public function __construct(
        private Connection $connection,
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

        if (false !== $this->connection->fetchOne('SELECT 1 FROM storefront_promo_code WHERE code = :code', ['code' => $code])) {
            return new JsonResponse(['error' => 'This promo code already exists.'], Response::HTTP_CONFLICT);
        }

        $active = !array_key_exists('active', $body) || true === $body['active'];

        $this->connection->executeStatement(
            'INSERT INTO storefront_promo_code (code, discount_basis_points, active) VALUES (:code, :bp, :active)',
            ['code' => $code, 'bp' => $basisPoints, 'active' => $active],
            ['bp' => ParameterType::INTEGER, 'active' => ParameterType::BOOLEAN],
        );

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
        $row = $this->connection->fetchAssociative(
            'SELECT discount_basis_points, active FROM storefront_promo_code WHERE code = :code',
            ['code' => $code],
        );
        if (false === $row) {
            return new JsonResponse(['error' => 'Promo code not found.'], Response::HTTP_NOT_FOUND);
        }

        $basisPoints = (int) $row['discount_basis_points'];
        if (array_key_exists('discountBasisPoints', $body)) {
            $parsed = $this->basisPoints($body['discountBasisPoints']);
            if (null === $parsed) {
                return new JsonResponse(['error' => 'discountBasisPoints must be an integer between 1 and 10000.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $basisPoints = $parsed;
        }

        $active = (bool) $row['active'];
        if (array_key_exists('active', $body)) {
            if (!is_bool($body['active'])) {
                return new JsonResponse(['error' => 'active must be a boolean.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $active = $body['active'];
        }

        $this->connection->executeStatement(
            'UPDATE storefront_promo_code SET discount_basis_points = :bp, active = :active WHERE code = :code',
            ['bp' => $basisPoints, 'active' => $active, 'code' => $code],
            ['bp' => ParameterType::INTEGER, 'active' => ParameterType::BOOLEAN],
        );

        return new JsonResponse(['code' => $code, 'discountBasisPoints' => $basisPoints, 'active' => $active]);
    }

    #[Route('/{code}', name: 'admin_promo_codes_delete', methods: ['DELETE'])]
    public function delete(string $code): JsonResponse
    {
        $code = strtoupper($code);
        $deleted = $this->connection->executeStatement('DELETE FROM storefront_promo_code WHERE code = :code', ['code' => $code]);
        if (0 === $deleted) {
            return new JsonResponse(['error' => 'Promo code not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['success' => true, 'code' => $code]);
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
