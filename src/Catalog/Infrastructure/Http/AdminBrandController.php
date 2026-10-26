<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Query\ListAdminBrands;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use App\Shared\Application\Pagination\Page;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office management of the brand list shown in the storefront filters.
 * A brand name is also stored on every product, so renames are propagated and
 * deleting a brand that still has products is refused.
 */
#[Route('/api/v1/admin/catalog/brands')]
#[IsGranted('ROLE_ADMIN')]
final class AdminBrandController
{
    public function __construct(
        private Connection $connection,
        private StorefrontProductSearchIndexer $searchIndex,
        private MessengerQuery $queries,
    ) {
    }

    /**
     * R11: one bounded page of the brand list with its per-brand product count.
     */
    #[Route('', name: 'admin_catalog_list_brands', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var Page $page */
        $page = $this->queries->dispatch(new ListAdminBrands(
            page: ListQueryParams::page($request, ListAdminBrands::DEFAULT_SIZE, ListAdminBrands::MAX_SIZE),
            q: ListQueryParams::text($request),
            sort: ListQueryParams::text($request, 'sort'),
        ));

        return new JsonResponse($page->toArray());
    }

    #[Route('', name: 'admin_catalog_create_brand', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $name = $this->nameFrom($request);
        if (null === $name) {
            return new JsonResponse(['error' => 'The brand name is required (64 characters max, no "/").'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->exists($name)) {
            return new JsonResponse(['error' => 'This brand already exists.'], Response::HTTP_CONFLICT);
        }

        $this->connection->executeStatement('INSERT INTO storefront_brand (name) VALUES (:name)', ['name' => $name]);

        return new JsonResponse(['name' => $name, 'productCount' => 0], Response::HTTP_CREATED);
    }

    #[Route('/{name}', name: 'admin_catalog_rename_brand', methods: ['PUT'])]
    public function rename(string $name, Request $request): JsonResponse
    {
        $newName = $this->nameFrom($request);
        if (null === $newName) {
            return new JsonResponse(['error' => 'The brand name is required (64 characters max, no "/").'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (false === $this->connection->fetchOne('SELECT 1 FROM storefront_brand WHERE name = :name', ['name' => $name])) {
            return new JsonResponse(['error' => 'Brand not found.'], Response::HTTP_NOT_FOUND);
        }

        $clash = $this->connection->fetchOne(
            'SELECT 1 FROM storefront_brand WHERE lower(name) = lower(:new) AND name <> :old',
            ['new' => $newName, 'old' => $name],
        );
        if (false !== $clash) {
            return new JsonResponse(['error' => 'This brand already exists.'], Response::HTTP_CONFLICT);
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('UPDATE storefront_brand SET name = :new WHERE name = :old', ['new' => $newName, 'old' => $name]);
            $this->connection->executeStatement('UPDATE storefront_product_view SET brand = :new WHERE brand = :old', ['new' => $newName, 'old' => $name]);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        // The brand travels inside every product document: re-index the renamed products (ECO-008).
        $slugs = $this->connection->fetchFirstColumn('SELECT slug FROM storefront_product_view WHERE brand = :name', ['name' => $newName]);
        foreach ($slugs as $slug) {
            $this->searchIndex->sync((string) $slug);
        }

        return new JsonResponse(['name' => $newName]);
    }

    #[Route('/{name}', name: 'admin_catalog_delete_brand', methods: ['DELETE'])]
    public function delete(string $name): JsonResponse
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM storefront_product_view WHERE brand = :name', ['name' => $name]);
        if ($count > 0) {
            return new JsonResponse([
                'error' => 'Brand In Use',
                'message' => sprintf('%d product(s) still use this brand. Reassign or delete them first.', $count),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $deleted = $this->connection->executeStatement('DELETE FROM storefront_brand WHERE name = :name', ['name' => $name]);
        if (0 === $deleted) {
            return new JsonResponse(['error' => 'Brand not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['success' => true, 'name' => $name]);
    }

    private function exists(string $name): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM storefront_brand WHERE lower(name) = lower(:name)',
            ['name' => $name],
        );
    }

    private function nameFrom(Request $request): ?string
    {
        $body = json_decode($request->getContent(), true);
        $name = is_array($body) && is_string($body['name'] ?? null) ? trim($body['name']) : '';

        if ('' === $name || mb_strlen($name) > 64 || str_contains($name, '/')) {
            return null;
        }

        return $name;
    }
}
