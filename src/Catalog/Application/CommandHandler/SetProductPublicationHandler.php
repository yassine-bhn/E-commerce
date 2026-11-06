<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SetProductPublication;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Application\Port\ProductPublicationStore;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use App\Catalog\Domain\Repository\CatalogSubmissionRepository;
use App\Shared\Application\Port\ProductSearchIndexPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R28/R29/R30 — publish or unpublish one product, and nothing else.
 *
 * This is the only writer of the `published` flag. It never touches `approval_status`, and it
 * refuses to publish a record that is not approved, so a pending submission cannot be leaked by
 * flipping the flag: the read side also requires APPROVED, which is the second guard. The search
 * index is refreshed here, because this write is what the index mirrors.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class SetProductPublicationHandler
{
    public function __construct(
        private readonly ProductPublicationStore $publications,
        private readonly CatalogAuditPort $audit,
        private readonly CatalogSubmissionRepository $submissions,
        private readonly ProductSearchIndexPort $search,
    ) {
    }

    public function __invoke(SetProductPublication $command): void
    {
        $submission = $this->submissions->find(CatalogSubmissionKind::Product, $command->slug);
        if (null === $submission) {
            throw CatalogSubmissionNotFoundException::for(CatalogSubmissionKind::Product, $command->slug);
        }

        $status = $submission->status();
        if ($command->published && !$status->isPubliclyVisible()) {
            throw ApprovalTransitionRefusedException::unapprovedCannotBePublished($command->slug, $status);
        }

        $this->publications->setPublished($command->slug, $command->published);

        $this->audit->record(
            $command->published ? 'CATALOG_PRODUCT_PUBLISHED' : 'CATALOG_PRODUCT_UNPUBLISHED',
            CatalogSubmissionKind::Product->auditTarget(),
            $command->slug,
            $command->actorId,
        );

        $this->search->sync($command->slug);
    }
}
