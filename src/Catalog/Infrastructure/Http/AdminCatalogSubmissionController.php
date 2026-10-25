<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http;

use App\Catalog\Application\Command\DecideCatalogSubmission;
use App\Catalog\Application\Command\SubmitCatalogSubmission;
use App\Catalog\Application\Query\GetCatalogSubmission;
use App\Catalog\Application\Query\ListCatalogSubmissions;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
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
 * spec-run-8 — the approval workflow surface for products and categories.
 *
 * The queue is the only way a reviewer finds work, the detail route is the only way an owner
 * reads their own decision, and approve/reject are the only write routes that can produce an
 * `APPROVED` or `REJECTED` row. Roles are enforced here and again by `/api/v1/admin`; ownership
 * is enforced by the handler or by the query predicate, never by the payload.
 */
#[Route('/api/v1/admin/catalog/submissions')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCatalogSubmissionController
{
    private const int MAX_REASON_LENGTH = 2000;

    public function __construct(
        private MessengerQuery $queries,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * R5/R15/R16 — the review queue: every row for a Super Admin, only your own otherwise,
     * optionally narrowed to one approval state.
     */
    #[Route('/{kind}', name: 'admin_catalog_list_submissions', methods: ['GET'])]
    public function list(string $kind, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $type = $this->kind($kind);
        if (null === $type) {
            return new JsonResponse(['error' => 'Unknown submission type.'], Response::HTTP_NOT_FOUND);
        }

        $status = $this->approvalStatus($request);

        /** @var Page $page */
        $page = $this->queries->dispatch(new ListCatalogSubmissions(
            kind: $type,
            requesterId: $admin->getId(),
            isSuperAdmin: $this->isSuperAdmin($admin),
            page: ListQueryParams::page($request, ListCatalogSubmissions::DEFAULT_SIZE, ListCatalogSubmissions::MAX_SIZE),
            approvalStatus: $status,
            q: ListQueryParams::text($request),
        ));

        return new JsonResponse($page->toArray());
    }

    /**
     * R5/R6 — one submission with its approval state and, when it was rejected, the reason.
     */
    #[Route('/{kind}/{slug}', name: 'admin_catalog_show_submission', methods: ['GET'])]
    public function show(string $kind, string $slug, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $payload = $this->view($kind, $slug, $admin);

        return null === $payload
            ? new JsonResponse(['error' => 'Submission not found.'], Response::HTTP_NOT_FOUND)
            : new JsonResponse($payload);
    }

    /**
     * R3/R4/R9 — submit (or resubmit after a rejection) for review.
     */
    #[Route('/{kind}/{slug}/submit', name: 'admin_catalog_submit_submission', methods: ['POST'])]
    #[Route('/{kind}/{slug}/resubmit', name: 'admin_catalog_resubmit_submission', methods: ['POST'])]
    public function submit(string $kind, string $slug, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        return $this->write($kind, $slug, $admin, fn (CatalogSubmissionKind $type): SubmitCatalogSubmission => new SubmitCatalogSubmission(
            $type,
            $slug,
            $admin->getId(),
            $this->isSuperAdmin($admin),
        ));
    }

    /**
     * R22/R34 — approving is a Super Admin decision and the only path to `APPROVED`.
     */
    #[Route('/{kind}/{slug}/approve', name: 'admin_catalog_approve_submission', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function approve(string $kind, string $slug, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        return $this->decide($kind, $slug, $admin, CatalogDecision::Approve, null);
    }

    /**
     * R23/R34 — rejecting is a Super Admin decision; the reason is optional and travels with it.
     */
    #[Route('/{kind}/{slug}/reject', name: 'admin_catalog_reject_submission', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function reject(string $kind, string $slug, Request $request, #[CurrentUser] CustomerUser $admin): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $reason = is_array($body) && is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            return new JsonResponse(
                ['error' => sprintf('The rejection reason is limited to %d characters.', self::MAX_REASON_LENGTH)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->decide($kind, $slug, $admin, CatalogDecision::Reject, '' === $reason ? null : $reason);
    }

    private function decide(
        string $kind,
        string $slug,
        CustomerUser $admin,
        CatalogDecision $decision,
        ?string $reason,
    ): JsonResponse {
        return $this->write($kind, $slug, $admin, static fn (CatalogSubmissionKind $type): DecideCatalogSubmission => new DecideCatalogSubmission(
            $type,
            $slug,
            $decision,
            $admin->getId(),
            $reason,
        ));
    }

    /**
     * Dispatches one workflow command and re-reads the row it changed, so the answer is always
     * the stored state rather than an echo of the request.
     *
     * @param callable(CatalogSubmissionKind): object $command
     */
    private function write(string $kind, string $slug, CustomerUser $admin, callable $command): JsonResponse
    {
        $type = $this->kind($kind);
        if (null === $type) {
            return new JsonResponse(['error' => 'Unknown submission type.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->commandBus->dispatch($command($type));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();

            return $previous instanceof \DomainException
                ? $this->domainFailure($previous)
                : throw $e;
        } catch (\DomainException $error) {
            return $this->domainFailure($error);
        }

        $payload = $this->view($kind, $slug, $admin);

        return null === $payload
            ? new JsonResponse(['error' => 'Submission not found.'], Response::HTTP_NOT_FOUND)
            : new JsonResponse($payload);
    }

    /** @return array<string, mixed>|null */
    private function view(string $kind, string $slug, CustomerUser $admin): ?array
    {
        $type = $this->kind($kind);
        if (null === $type) {
            return null;
        }

        /** @var \App\Catalog\Infrastructure\ReadModel\CatalogSubmissionView|null $view */
        $view = $this->queries->dispatch(new GetCatalogSubmission(
            $type,
            $slug,
            $admin->getId(),
            $this->isSuperAdmin($admin),
        ));

        return $view?->toApi();
    }

    private function domainFailure(\DomainException $error): JsonResponse
    {
        if ($error instanceof CatalogSubmissionNotFoundException) {
            return new JsonResponse(['error' => 'Submission not found.', 'message' => $error->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($error instanceof CatalogSubmissionNotOwnedException) {
            return new JsonResponse(['error' => 'Forbidden', 'message' => $error->getMessage()], Response::HTTP_FORBIDDEN);
        }

        if ($error instanceof ApprovalTransitionRefusedException) {
            return new JsonResponse(['error' => 'Approval Transition Refused', 'message' => $error->getMessage()], Response::HTTP_CONFLICT);
        }

        throw $error;
    }

    private function kind(string $kind): ?CatalogSubmissionKind
    {
        return CatalogSubmissionKind::tryFrom($kind);
    }

    private function approvalStatus(Request $request): ?ApprovalStatus
    {
        $status = ListQueryParams::text($request, 'status');
        if (null === $status) {
            return null;
        }

        return ApprovalStatus::tryFrom(strtoupper($status));
    }

    private function isSuperAdmin(CustomerUser $admin): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $admin->getRoles(), true);
    }
}
