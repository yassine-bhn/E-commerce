<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\DecideCatalogSubmission;
use App\Catalog\Application\Port\CatalogSubmissionStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * R22/R23/R26 — one review outcome, written in one transaction.
 *
 * The store is the only writer: it refuses anything but a pending submission, requires a rejection
 * reason, writes the decision and its audit/outbox entries together and projects the view. No CRUD
 * path can approve a record by accident because no handler writes those columns directly.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DecideCatalogSubmissionHandler
{
    public function __construct(private readonly CatalogSubmissionStore $submissions)
    {
    }

    public function __invoke(DecideCatalogSubmission $command): void
    {
        $this->submissions->decide(
            $command->kind,
            $command->slug,
            $command->decision,
            $command->reviewerId,
            $command->reason,
        );
    }
}
