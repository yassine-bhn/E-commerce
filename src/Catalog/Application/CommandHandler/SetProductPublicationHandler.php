<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SetProductPublication;
use App\Catalog\Application\Port\CatalogAuditPort;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\ApprovalTransitionRefusedException;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use App\Catalog\Domain\Model\CatalogSubmissionNotFoundException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R28/R29/R30 — publish or unpublish one product, and nothing else.
 *
 * This is the only writer of the `published` flag. It never touches `approval_status`, and it
 * refuses to publish a record that is not approved, so a pending submission cannot be leaked by
 * flipping the flag: the read side also requires APPROVED, which is the second guard.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class SetProductPublicationHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogAuditPort $audit,
    ) {
    }

    public function __invoke(SetProductPublication $command): void
    {
        $this->connection->transactional(function () use ($command): void {
            $stored = $this->connection->fetchOne(
                'SELECT approval_status FROM storefront_product_view WHERE slug = :slug',
                ['slug' => $command->slug],
            );
            if (!is_string($stored)) {
                throw CatalogSubmissionNotFoundException::for(CatalogSubmissionKind::Product, $command->slug);
            }

            $status = ApprovalStatus::fromStorage($stored);
            if ($command->published && !$status->isPubliclyVisible()) {
                throw ApprovalTransitionRefusedException::unapprovedCannotBePublished($command->slug, $status);
            }

            $this->connection->executeStatement(
                'UPDATE storefront_product_view SET published = :published WHERE slug = :slug',
                ['published' => $command->published, 'slug' => $command->slug],
                ['published' => ParameterType::BOOLEAN],
            );

            $this->audit->record(
                $command->published ? 'CATALOG_PRODUCT_PUBLISHED' : 'CATALOG_PRODUCT_UNPUBLISHED',
                CatalogSubmissionKind::Product->auditTarget(),
                $command->slug,
                $command->actorId,
            );
        });
    }
}
