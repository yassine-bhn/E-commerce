<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\SubmitCatalogSubmission;
use App\Catalog\Application\Port\CatalogSubmissionStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R3/R4/R9/R24/R39 — puts a submission in front of a reviewer, and only the owner (or a Super
 * Admin acting for them) may do it.
 *
 * The store owns the ownership check, the state transition, its outbox event and its audit row in
 * one transaction, so a refused submission leaves the record exactly as it was and writes no
 * history. Resubmission also clears the previous reviewer, decision moment and rejection reason.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class SubmitCatalogSubmissionHandler
{
    public function __construct(private readonly CatalogSubmissionStore $submissions)
    {
    }

    public function __invoke(SubmitCatalogSubmission $command): void
    {
        $this->submissions->submit(
            $command->kind,
            $command->slug,
            $command->actorId,
            $command->actorIsSuperAdmin,
        );
    }
}
