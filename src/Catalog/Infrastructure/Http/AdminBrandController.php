<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\CreateBrand;
use App\Catalog\Application\Command\DeleteBrand;
use App\Catalog\Application\Command\RenameBrand;
use App\Catalog\Application\Query\ListAdminBrands;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\BrandAlreadyExistsException;
use App\Catalog\Domain\Model\BrandNotEmptyException;
use App\Catalog\Domain\Model\BrandNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Model\CatalogSubmissionNotOwnedException;
use App\Identity\Infrastructure\Security\CustomerUser;
use App\Shared\Application\Pagination\Page;
use App\Shared\Infrastructure\Http\ListQueryParams;
use App\Shared\Infrastructure\Http\MessengerQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office management of the brand list shown in the storefront filters.
 *
 * Brands follow the same approval workflow as categories: every write is one command on
 * `command.bus` — the handlers own the store transitions, the access policy and the audit — and
 * every read is one query on `query.bus`. The edge only validates the name shape and maps the
 * domain's answer to HTTP (ERR-002).
 */
#[Route('/api/v1/admin/catalog/brands')]
final class AdminBrandController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessengerQuery $queries,
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
            $this->commandBus->dispatch(new CreateBrand($name, $admin->getId()));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
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
            $this->commandBus->dispatch(new RenameBrand($name, $newName, $admin->getId(), $this->isSuperAdmin($admin)));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
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
            $this->commandBus->dispatch(new DeleteBrand($name, $admin->getId()));
        } catch (HandlerFailedException $exception) {
            return $this->domainFailure($this->rootCause($exception));
        }

        return new JsonResponse(['success' => true, 'name' => $name]);
    }

    private function isSuperAdmin(CustomerUser $admin): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true);
    }

    private function rootCause(HandlerFailedException $exception): \Throwable
    {
        $cause = $exception;

        while ($cause instanceof HandlerFailedException && null !== $cause->getPrevious()) {
            $cause = $cause->getPrevious();
        }

        return $cause;
    }

    /**
     * ERR-002: the domain says what is wrong, the edge decides what HTTP means.
     */
    private function domainFailure(\Throwable $error): JsonResponse
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
