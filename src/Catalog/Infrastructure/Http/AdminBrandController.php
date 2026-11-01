<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\CatalogSubmissionCreator;
use App\Catalog\Application\Port\CatalogSubmissionDeleter;
use App\Catalog\Application\Port\CatalogSubmissionOwnerEditor;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Application\Query\ListAdminBrands;
use App\Catalog\Application\Service\SubmissionAccessPolicy;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\BrandAlreadyExistsException;
use App\Catalog\Domain\Model\BrandNotEmptyException;
use App\Catalog\Domain\Model\BrandNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Domain\Repository\CatalogSubmissionRepository;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Application\Pagination\Page;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office management of the brand list shown in the storefront filters.
 *
 * Brands follow the same approval workflow as categories: every write goes through the
 * submission store (one `catalog_submission` row per brand, keyed by its name), a rename is
 * authorised by {@see SubmissionAccessPolicy} — a Super Admin writes anything, a regular Admin
 * only their own pending submission — and every action lands in the audit trail. Creating is
 * approved immediately, like a category creation, so the brand can be used without a second
 * step; deleting stays a Super Admin decision and removes the submission with the row.
 */
#[Route('/api/v1/admin/catalog/brands')]
final class AdminBrandController
{
    public function __construct(
        private Connection $connection,
        private StorefrontProductSearchIndexer $searchIndex,
        private MessengerQuery $queries,
        private SubmissionAccessPolicy $access,
        private CatalogAuditPort $audit,
        private CatalogSubmissionCreator $submissions,
        private CatalogSubmissionReviewer $reviews,
        private CatalogSubmissionOwnerEditor $ownerEdits,
        private CatalogSubmissionDeleter $deletes,
        private CatalogSubmissionRepository $submissionsRead,
    ) {
    }

    /**
     * R11: one bounded page of the brand list with its per-brand product count.
     */
    #[Route('', name: 'admin_catalog_list_brands', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
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

    /**
     * Creating a brand is approved at once (the same rule as a category creation), so the
     * storefront filters can offer it without a second decision.
     */
    #[Route('', name: 'admin_catalog_create_brand', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $name = $this->nameFrom($request);
        if (null === $name) {
            return new JsonResponse(['error' => 'The brand name is required (64 characters max, no "/").'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->connection->transactional(function () use ($name, $admin): void {
                if ($this->exists($name)) {
                    throw BrandAlreadyExistsException::forName($name);
                }

                $this->connection->executeStatement('INSERT INTO storefront_brand (name) VALUES (:name)', ['name' => $name]);

                $this->submissions->create(CatalogSubmissionKind::Brand, $name, $admin->getId());
                $this->reviews->reviewerSave(CatalogSubmissionKind::Brand, $name, $admin->getId());
            });
        } catch (\DomainException $error) {
            return $this->domainFailure($error);
        }

        return new JsonResponse(['name' => $name, 'productCount' => 0], Response::HTTP_CREATED);
    }

    /**
     * A rename is the one write that changes the record's identity: the brand row, every
     * product carrying the name and the submission row itself move together. The access
     * policy is read before anything is written, so a refused rename leaves all three alone.
     */
    #[Route('/{name}', name: 'admin_catalog_rename_brand', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rename(string $name, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $newName = $this->nameFrom($request);
        if (null === $newName) {
            return new JsonResponse(['error' => 'The brand name is required (64 characters max, no "/").'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($newName === $name) {
            return new JsonResponse(['name' => $name]);
        }

        try {
            $this->connection->transactional(function () use ($name, $newName, $admin): void {
                $this->access->authorize(
                    CatalogSubmissionKind::Brand,
                    $name,
                    $admin->getId(),
                    $this->isSuperAdmin($admin),
                );

                $clash = $this->connection->fetchOne(
                    'SELECT 1 FROM storefront_brand WHERE lower(name) = lower(:new) AND name <> :old',
                    ['new' => $newName, 'old' => $name],
                );
                if (false !== $clash) {
                    throw BrandAlreadyExistsException::forName($newName);
                }

                $renamed = $this->connection->executeStatement(
                    'UPDATE storefront_brand SET name = :new WHERE name = :old',
                    ['new' => $newName, 'old' => $name],
                );
                if (0 === $renamed) {
                    throw BrandNotFoundException::forName($name);
                }

                $this->connection->executeStatement(
                    'UPDATE storefront_product_view SET brand = :new WHERE brand = :old',
                    ['new' => $newName, 'old' => $name],
                );

                // The submission follows its brand: the row keeps its whole state under the new
                // key, an orphan at that key (a brand deleted before the workflow existed) cannot
                // block it, and a legacy brand with no submission gets one, approved like a create.
                if (null !== $this->submissionsRead->find(CatalogSubmissionKind::Brand, $name)) {
                    $this->ownerEdits->move(CatalogSubmissionKind::Brand, $name, $newName);
                } else {
                    $this->submissions->create(CatalogSubmissionKind::Brand, $newName, $admin->getId());
                }
                $this->reviews->reviewerSave(CatalogSubmissionKind::Brand, $newName, $admin->getId());

                $this->audit->record(
                    'CATALOG_BRAND_RENAMED',
                    CatalogSubmissionKind::Brand->auditTarget(),
                    $newName,
                    $admin->getId(),
                    sprintf('Renamed from "%s".', $name),
                );
            });
        } catch (\DomainException $error) {
            return $this->domainFailure($error);
        }

        // The brand travels inside every product document: re-index the renamed products (ECO-008).
        $slugs = $this->connection->fetchFirstColumn('SELECT slug FROM storefront_product_view WHERE brand = :name', ['name' => $newName]);
        foreach ($slugs as $slug) {
            $this->searchIndex->sync((string) $slug);
        }

        return new JsonResponse(['name' => $newName]);
    }

    /**
     * spec-run-8 R20/R34 — deleting a catalog record is a Super Admin decision, whatever its
     * approval state, and slug (name) reuse must not inherit a previous approval (R11).
     */
    #[Route('/{name}', name: 'admin_catalog_delete_brand', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(string $name, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        try {
            $this->connection->transactional(function () use ($name, $admin): void {
                $count = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM storefront_product_view WHERE brand = :name',
                    ['name' => $name],
                );
                if ($count > 0) {
                    throw BrandNotEmptyException::forBrand($name, $count);
                }

                $deleted = $this->connection->executeStatement('DELETE FROM storefront_brand WHERE name = :name', ['name' => $name]);
                if (0 === $deleted) {
                    throw BrandNotFoundException::forName($name);
                }

                $this->deletes->delete(CatalogSubmissionKind::Brand, $name);

                $this->audit->record(
                    'CATALOG_BRAND_DELETED',
                    CatalogSubmissionKind::Brand->auditTarget(),
                    $name,
                    $admin->getId(),
                );
            });
        } catch (\DomainException $error) {
            return $this->domainFailure($error);
        }

        return new JsonResponse(['success' => true, 'name' => $name]);
    }

    private function isSuperAdmin(CustomerUser $admin): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true);
    }

    /**
     * ERR-002: the domain says what is wrong, the edge decides what HTTP means.
     */
    private function domainFailure(\DomainException $error): JsonResponse
    {
        if ($error instanceof BrandAlreadyExistsException) {
            return new JsonResponse(['error' => 'This brand already exists.'], Response::HTTP_CONFLICT);
        }

        if ($error instanceof BrandNotFoundException || $error instanceof CatalogSubmissionNotFoundException) {
            return new JsonResponse(['error' => 'Brand not found.', 'message' => $error->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($error instanceof BrandNotEmptyException) {
            return new JsonResponse(['error' => 'Brand In Use', 'message' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($error instanceof CatalogSubmissionNotOwnedException) {
            return new JsonResponse(['error' => 'Forbidden', 'message' => $error->getMessage()], Response::HTTP_FORBIDDEN);
        }

        if ($error instanceof ApprovalTransitionRefusedException) {
            return new JsonResponse(['error' => 'Approval Transition Refused', 'message' => $error->getMessage()], Response::HTTP_CONFLICT);
        }

        throw $error;
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
