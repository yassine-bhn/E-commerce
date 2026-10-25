<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\AddCategorySubLevel;
use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\DeleteCategory;
use App\Catalog\Application\Command\HideCategory;
use App\Catalog\Application\Command\ReplaceCategoryTree;
use App\Catalog\Application\Command\ShowCategory;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Query\ListCategories;
use App\Catalog\Application\Service\CategoryHierarchyValidator;
use App\Catalog\Application\Service\ProductDeletionGuard;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\CategoryAlreadyExistsException;
use App\Catalog\Domain\Model\CategoryHierarchyCycleException;
use App\Catalog\Domain\Model\CategoryNotEmptyException;
use App\Catalog\Domain\Model\CategoryNotFoundException;
use App\Catalog\Domain\Model\CategorySublevelInUseException;
use App\Catalog\Domain\Model\ProductHasOrdersCannotBeDeletedException;
use App\Catalog\Infrastructure\ReadModel\CategoryView;
use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Application\Pagination\Page;
use App\Shared\Application\Pagination\PageRequest;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Category management for the admin dashboard (spec R1-R8).
 *
 * Every route of this spec goes through the documented CQRS path (Q2/R25): a command DTO on
 * `command.bus` for the writes, one DBAL-only authorized finder for the reads. The raw SQL
 * that is still here belongs to the legacy routes this spec does not touch.
 */
#[Route('/api/v1/admin/catalog')]
final class AdminCategoryController
{
    private const int MAX_SLUG_LENGTH = 64;

    /** Privileged accounts allowed to read the category tree exactly as the other admin surfaces. */
    private const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(
        private CategoryHierarchyValidator $categoryValidator,
        private ProductDeletionGuard $productGuard,
        private Connection $connection,
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
        private StorefrontProductSearchIndexer $searchIndex,
        private CatalogAuditPort $audit,
    ) {
    }

    #[Route('/categories/validate-parent', name: 'admin_catalog_validate_parent', methods: ['POST'])]
    public function validateParent(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $categorySlug = trim((string) ($body['categorySlug'] ?? ''));
        $parentSlug = isset($body['parentSlug']) && '' !== trim((string) $body['parentSlug'])
            ? trim((string) $body['parentSlug'])
            : null;

        if ('' === $categorySlug) {
            return new JsonResponse(['error' => 'categorySlug is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->categoryValidator->validateParentAssignment($categorySlug, $parentSlug);
        } catch (CategoryHierarchyCycleException $e) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'Cycle Detected',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['valid' => true, 'categorySlug' => $categorySlug, 'parentSlug' => $parentSlug]);
    }

    /**
     * R8/R10: one bounded page of the taxonomy the dashboard edits, hidden rows included.
     */
    #[Route('/categories', name: 'admin_catalog_list_categories', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listCategories(Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        /** @var Page $page */
        $page = $this->queries->dispatch(new ListCategories(
            requesterId: $admin->getId(),
            isAdmin: [] !== array_intersect(self::ADMIN_ROLES, $admin->getRoles()),
            page: ListQueryParams::page($request, ListCategories::DEFAULT_SIZE, ListCategories::MAX_SIZE),
            q: ListQueryParams::text($request),
            hidden: ListQueryParams::boolOrNull($request, 'hidden'),
            parent: ListQueryParams::text($request, 'parent'),
            sort: ListQueryParams::text($request, 'sort'),
        ));

        return new JsonResponse($page->toArray());
    }

    /**
     * R1: create a top-level category. The label is required, the slug optional
     * (derived from the label like every other admin surface), and a refused payload
     * writes nothing.
     */
    #[Route('/categories', name: 'admin_catalog_create_category', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createCategory(Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $label = $this->text($body['label'] ?? null);
        if ('' === $label) {
            return new JsonResponse(['error' => 'The category label is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $slug = $this->text($body['slug'] ?? null);
        if ('' === $slug) {
            $slug = mb_substr(strtolower((new AsciiSlugger('fr'))->slug($label)->toString()), 0, self::MAX_SLUG_LENGTH);
        }
        $error = $this->checkSlug($slug);
        if (null !== $error) {
            return $error;
        }

        $subcategories = $this->tree($body['subcategories'] ?? []);
        if (null === $subcategories) {
            return new JsonResponse(['error' => 'The subcategories must be a list of {label, children?} nodes.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $quoteEligible = $this->flag($body['quoteEligible'] ?? false);
        if (null === $quoteEligible) {
            return new JsonResponse(['error' => 'quoteEligible must be a boolean.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $failure = $this->dispatch(new CreateCategory($slug, $label, $subcategories, $quoteEligible, $admin->getId()));
        if (null !== $failure) {
            return $failure;
        }

        return $this->respond($slug, $admin, Response::HTTP_CREATED);
    }

    /**
     * R2: add one sub-category node to the `subcategories` tree of an existing category
     * (Q1: a node of the parent row, never a child row).
     */
    #[Route('/categories/{slug}/subcategories', name: 'admin_catalog_add_category_sub_level', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addSubLevel(string $slug, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->checkSlug($slug);
        if (null !== $error) {
            return $error;
        }

        $label = $this->text($body['label'] ?? null);
        if ('' === $label) {
            return new JsonResponse(['error' => 'The subcategory label is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $parentLabel = $this->text($body['parentLabel'] ?? $body['parent'] ?? null);
        if (mb_strlen($parentLabel) > Category::MAX_LABEL_LENGTH) {
            return new JsonResponse(['error' => 'The parent subcategory label is too long.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $failure = $this->dispatch(new AddCategorySubLevel(
            $slug,
            $label,
            '' === $parentLabel ? null : $parentLabel,
            $admin->getId(),
            $this->isSuperAdmin($admin),
        ));
        if (null !== $failure) {
            return $failure;
        }

        return $this->respond($slug, $admin, Response::HTTP_CREATED);
    }

    /**
     * R3 (and the WIP dashboard contract): replace the editable content of a category —
     * label, quote eligibility and the whole sub-level tree. A tree that would drop a
     * sub-level a product still points at is refused and changes nothing.
     */
    #[Route('/categories/{slug}', name: 'admin_catalog_update_category', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateCategory(string $slug, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->checkSlug($slug);
        if (null !== $error) {
            return $error;
        }

        $label = $this->text($body['label'] ?? null);
        if ('' === $label) {
            return new JsonResponse(['error' => 'The category label is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subcategories = $this->tree($body['subcategories'] ?? []);
        if (null === $subcategories) {
            return new JsonResponse(['error' => 'The subcategories must be a list of {label, children?} nodes.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $quoteEligible = $this->flag($body['quoteEligible'] ?? false);
        if (null === $quoteEligible) {
            return new JsonResponse(['error' => 'quoteEligible must be a boolean.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $failure = $this->dispatch(new ReplaceCategoryTree(
            $slug,
            $label,
            $subcategories,
            $quoteEligible,
            $admin->getId(),
            $this->isSuperAdmin($admin),
        ));
        if (null !== $failure) {
            return $failure;
        }

        return $this->respond($slug, $admin);
    }

    /**
     * R5: hide or un-hide a category. Only the visibility flag is persisted, the call is
     * idempotent and the resulting flag is what comes back.
     */
    #[Route('/categories/{slug}/visibility', name: 'admin_catalog_category_visibility', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function setVisibility(string $slug, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->checkSlug($slug);
        if (null !== $error) {
            return $error;
        }

        if (!array_key_exists('hidden', $body)) {
            return new JsonResponse(['error' => 'hidden must be a boolean.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $hidden = $this->flag($body['hidden']);
        if (null === $hidden) {
            return new JsonResponse(['error' => 'hidden must be a boolean.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $failure = $this->dispatch($hidden
            ? new HideCategory($slug, $admin->getId(), $this->isSuperAdmin($admin))
            : new ShowCategory($slug, $admin->getId(), $this->isSuperAdmin($admin)));
        if (null !== $failure) {
            return $failure;
        }

        return $this->respond($slug, $admin);
    }

    /**
     * R4: the delete path stays, but the children it counts are the nodes of the row's
     * `subcategories` JSON tree (plus the legacy `parent_slug` rows). An empty category is
     * removed, a non-empty one is refused with its counts and nothing written, and deleting
     * it again answers not-found instead of a 500.
     *
     * spec-run-8 R20/R34 — deleting a catalog record is a Super Admin decision, whatever its
     * approval state, so the role is checked here and again by `/api/v1/admin` access control.
     */
    #[Route('/categories/{slug}', name: 'admin_catalog_delete_category', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function deleteCategory(string $slug, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $error = $this->checkSlug($slug);
        if (null !== $error) {
            return $error;
        }

        $failure = $this->dispatch(new DeleteCategory($slug, $admin->getId()));
        if (null !== $failure) {
            return $failure;
        }

        return new JsonResponse(['success' => true, 'slug' => $slug, 'message' => 'Category deleted successfully']);
    }

    /**
     * spec-run-8 R19/R20/R42 — deletion keeps its order-history guard, never touches the
     * approval decision, and is the Super Admin's call alone.
     */
    #[Route('/products/{slug}', name: 'admin_catalog_delete_product', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function deleteProduct(string $slug, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        if (false === $this->connection->fetchOne(
            'SELECT 1 FROM storefront_product_view WHERE slug = :slug',
            ['slug' => $slug],
        )) {
            return new JsonResponse(['error' => 'Product not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->productGuard->ensureCanDeleteProduct($slug);
        } catch (ProductHasOrdersCannotBeDeletedException $e) {
            return new JsonResponse([
                'error' => 'Product Has Historical Orders',
                'message' => $e->getMessage(),
                'advice' => 'Unpublish or hide the product instead of deleting it to preserve order history and accounting integrity.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->connection->transactional(function () use ($slug, $admin): void {
            $this->connection->executeStatement(
                'DELETE FROM storefront_product_view WHERE slug = :slug',
                ['slug' => $slug],
            );

            $this->audit->record(
                'CATALOG_PRODUCT_DELETED',
                CatalogSubmissionKind::Product->auditTarget(),
                $slug,
                $admin->getId(),
            );
        });

        // Best-effort: the read model stays authoritative if Meilisearch is down (ECO-008).
        $this->searchIndex->forget($slug);

        return new JsonResponse(['success' => true, 'slug' => $slug, 'message' => 'Product deleted successfully']);
    }

    private function isSuperAdmin(CustomerUser $admin): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true);
    }

    /**
     * Dispatches one category command on `command.bus` and answers its domain failure.
     *
     * The handler is the transaction boundary, so Messenger's doctrine middleware wraps
     * whatever it throws: the unwrapped domain exception is what the edge maps to HTTP,
     * and a refused write leaves the row exactly as it was.
     */
    private function dispatch(object $command): ?JsonResponse
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            if (!$previous instanceof \DomainException) {
                throw $e;
            }

            return $this->domainFailure($previous);
        } catch (\DomainException $e) {
            return $this->domainFailure($e);
        }

        return null;
    }

    /**
     * ERR-002: the domain says what is wrong, the edge decides what HTTP means.
     */
    private function domainFailure(\DomainException $error): JsonResponse
    {
        if ($error instanceof CatalogSubmissionNotFoundException || $error instanceof CategoryNotFoundException) {
            return new JsonResponse(['error' => 'Category not found.', 'message' => $error->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($error instanceof CatalogSubmissionNotOwnedException) {
            return new JsonResponse(['error' => 'Forbidden', 'message' => $error->getMessage()], Response::HTTP_FORBIDDEN);
        }

        if ($error instanceof ApprovalTransitionRefusedException) {
            return new JsonResponse(['error' => 'Approval Transition Refused', 'message' => $error->getMessage()], Response::HTTP_CONFLICT);
        }

        if ($error instanceof CategoryAlreadyExistsException) {
            return new JsonResponse(['error' => 'A category with this slug already exists.', 'message' => $error->getMessage()], Response::HTTP_CONFLICT);
        }

        $payload = ['error' => 'Invalid Category', 'message' => $error->getMessage()];
        if ($error instanceof CategoryNotEmptyException) {
            $payload = ['error' => 'Category Not Empty', 'message' => $error->getMessage()];
        } elseif ($error instanceof CategorySublevelInUseException) {
            $payload = ['error' => 'Subcategory In Use', 'message' => $error->getMessage()];
        }

        return new JsonResponse($payload, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The category as the dashboard read sees it, right after the write that changed it.
     */
    private function respond(string $slug, CustomerUser $admin, int $created = Response::HTTP_OK): JsonResponse
    {
        foreach ($this->categories($admin, $slug) as $view) {
            if ($view->slug === $slug) {
                return new JsonResponse($view->toApi(), $created);
            }
        }

        return new JsonResponse(['error' => 'Category not found.'], Response::HTTP_NOT_FOUND);
    }

    /**
     * The admin rows for one free-text term (none means every row), read through the same
     * bounded query the list endpoint uses.
     *
     * @return list<CategoryView>
     */
    private function categories(CustomerUser $admin, ?string $q = null): array
    {
        /** @var Page $page */
        $page = $this->queries->dispatch(new ListCategories(
            requesterId: $admin->getId(),
            isAdmin: [] !== array_intersect(self::ADMIN_ROLES, $admin->getRoles()),
            page: new PageRequest(1, ListCategories::MAX_SIZE, ListCategories::MAX_SIZE),
            q: $q,
        ));

        /** @var list<CategoryView> $views */
        $views = $page->items;

        return $views;
    }

    /** @return array<string, mixed>|null */
    private function decode(Request $request): ?array
    {
        $body = json_decode($request->getContent(), true);

        /** @var array<string, mixed>|null $result */
        $result = is_array($body) ? $body : null;

        return $result;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function flag(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * The sub-category tree the dashboard edits: a list of `{label, children?}` nodes.
     * A legacy list of bare labels is accepted as the top level of that same tree, and
     * only the shape is checked here — the depth, label and sibling rules stay in the
     * domain (`CategorySubtree`), so the edge cannot weaken them (R3).
     *
     * @return list<array<string, mixed>>|null
     */
    private function tree(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $nodes = [];
        foreach ($raw as $node) {
            if (is_string($node)) {
                $nodes[] = ['label' => $node];
                continue;
            }

            if (!is_array($node)) {
                return null;
            }

            /* @var array<string, mixed> $node */
            $nodes[] = $node;
        }

        return $nodes;
    }

    private function checkSlug(string $slug): ?JsonResponse
    {
        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > self::MAX_SLUG_LENGTH) {
            return new JsonResponse(
                ['error' => sprintf('The slug may only contain lowercase letters, digits and dashes (%d characters max).', self::MAX_SLUG_LENGTH)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return null;
    }
}
