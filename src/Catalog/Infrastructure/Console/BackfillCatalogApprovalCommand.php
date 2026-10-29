<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Console;

use App\Catalog\Application\Port\CatalogSubmissionStore;
use App\Catalog\Domain\Model\ApprovalStatus;
use App\Catalog\Domain\Model\CatalogDecision;
use App\Catalog\Domain\Model\CatalogSubmission;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * spec-run-12 R13 / spec-run-15 R11 — seeds the approval write model and approves pre-workflow rows.
 *
 * The migration used to do this with two blanket UPDATEs, which is exactly what MIG-002 forbids: a
 * migration that writes read-model rows re-applies the same write on every `up()`, so a rollback
 * followed by a re-run silently re-published products a Super Admin had rejected. The schema change
 * stays in the migration; the data change is this explicit, dry-run-by-default operator step.
 *
 * Step one copies every view row that has no `catalog_submission` row exactly as it stands, through
 * the store's import path: already-approved and rejected rows keep their reviewer and reason, and a
 * submitted row keeps its submitter and moment. Step two approves the remaining legacy rows (no
 * workflow metadata at all, still PENDING_APPROVAL) through `decide()`, so their audit and outbox
 * entries are the same ones a reviewer would have produced. It also publishes the approved products.
 *
 * The selection is "no workflow metadata at all", not a date: neither view table has a created_at, so
 * a row that was submitted or reviewed is the only thing that can be told apart from a legacy one.
 * That combination is what makes a second run import zero, approve zero and report zero.
 */
#[AsCommand(
    name: 'app:catalog:backfill-approval',
    description: 'Import legacy approval state and approve pre-workflow catalogue rows (dry-run by default)',
)]
final class BackfillCatalogApprovalCommand extends Command
{
    /** A row that carries none of this has never been through the workflow. */
    private const string LEGACY_ROW =
        'v.submitted_by IS NULL AND v.submitted_at IS NULL AND v.reviewed_by IS NULL AND v.reviewed_at IS NULL';

    /** No human decided these rows; the trail names the release step that did. */
    private const string ACTOR = 'system:backfill-approval';

    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogSubmissionStore $submissions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'apply',
            null,
            InputOption::VALUE_NONE,
            'Write the changes; without it the command only reports what it would do',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = true === $input->getOption('apply');

        // R11 asks for both halves of the report: what the step changed, and what it left alone. The
        // second number is the operator's only signal that the one-off step is complete.
        $imports = [
            CatalogSubmissionKind::Product->value => $this->importCandidates(CatalogSubmissionKind::Product),
            CatalogSubmissionKind::Category->value => $this->importCandidates(CatalogSubmissionKind::Category),
        ];
        $approvals = [
            CatalogSubmissionKind::Product->value => $this->approvalCandidates(CatalogSubmissionKind::Product),
            CatalogSubmissionKind::Category->value => $this->approvalCandidates(CatalogSubmissionKind::Category),
        ];

        $importCount = count($imports[CatalogSubmissionKind::Product->value]) + count($imports[CatalogSubmissionKind::Category->value]);
        $approvalCount = count($approvals[CatalogSubmissionKind::Product->value]) + count($approvals[CatalogSubmissionKind::Category->value]);
        $untouched = $this->untouched($imports, $approvals);

        if (!$apply) {
            $output->writeln(sprintf(
                '<comment>Dry run: would import %d submission(s) and approve %d pending row(s); %d row(s) left untouched.</comment>',
                $importCount,
                $approvalCount,
                $untouched,
            ));
            $output->writeln('Nothing was written. Re-run with --apply to apply the backfill.');

            return Command::SUCCESS;
        }

        [$imported, $approvedProducts, $approvedCategories] = $this->connection->transactional(function () use ($imports): array {
            $imported = $this->importAll($imports);

            return [
                $imported,
                $this->approve(CatalogSubmissionKind::Product),
                $this->approve(CatalogSubmissionKind::Category),
            ];
        });

        $output->writeln(sprintf(
            'Imported %d submission(s); approved %d product(s) (published) and %d category/categories; %d row(s) left untouched.',
            $imported,
            $approvedProducts,
            $approvedCategories,
            $untouched,
        ));

        if ($approvedProducts > 0) {
            $output->writeln('The search index only holds visible products: run "app:storefront:index-search" to publish them to search.');
        }

        return Command::SUCCESS;
    }

    /**
     * Every view row that has no submission yet, with the metadata the import must preserve.
     *
     * @return list<array<string, mixed>>
     */
    private function importCandidates(CatalogSubmissionKind $kind): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT v.slug, v.approval_status, v.submitted_by, v.submitted_at, v.reviewed_by, v.reviewed_at,
                        v.rejection_reason
                   FROM %s v
                   LEFT JOIN catalog_submission s ON s.kind = :kind AND s.slug = v.slug
                  WHERE s.slug IS NULL
                  ORDER BY v.slug ASC',
                $kind->table(),
            ),
            ['kind' => $kind->value],
        );
    }

    /**
     * Legacy pending rows that step two may decide: the submission they will have (or already have)
     * is pending, so a rejected or approved state somebody already chose is never overwritten.
     *
     * @return list<string>
     */
    private function approvalCandidates(CatalogSubmissionKind $kind): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['slug'],
            $this->connection->fetchAllAssociative(
                sprintf(
                    "SELECT v.slug
                       FROM %s v
                       LEFT JOIN catalog_submission s ON s.kind = :kind AND s.slug = v.slug
                      WHERE %s AND v.approval_status = 'PENDING_APPROVAL'
                        AND (s.slug IS NULL OR s.approval_status = 'PENDING_APPROVAL')
                      ORDER BY v.slug ASC",
                    $kind->table(),
                    self::LEGACY_ROW,
                ),
                ['kind' => $kind->value],
            ),
        );
    }

    /**
     * @param array<string, list<array<string, mixed>>> $imports
     */
    private function importAll(array $imports): int
    {
        $imported = 0;
        foreach ($imports as $kindValue => $rows) {
            $kind = CatalogSubmissionKind::from($kindValue);
            foreach ($rows as $row) {
                $submission = CatalogSubmission::reconstitute(
                    $kind,
                    (string) $row['slug'],
                    ApprovalStatus::fromStorage((string) $row['approval_status']),
                    $this->nullableString($row['submitted_by'] ?? null),
                    $this->nullableDate($row['submitted_at'] ?? null),
                    $this->nullableString($row['reviewed_by'] ?? null),
                    $this->nullableDate($row['reviewed_at'] ?? null),
                    $this->nullableString($row['rejection_reason'] ?? null),
                    new \DateTimeImmutable(),
                );

                if ($this->submissions->import($submission)) {
                    ++$imported;
                }
            }
        }

        return $imported;
    }

    private function approve(CatalogSubmissionKind $kind): int
    {
        $approved = 0;
        foreach ($this->approvalCandidates($kind) as $slug) {
            $this->submissions->decide($kind, $slug, CatalogDecision::Approve, self::ACTOR);
            if (CatalogSubmissionKind::Product === $kind) {
                $this->connection->executeStatement(
                    'UPDATE storefront_product_view SET published = true WHERE slug = :slug',
                    ['slug' => $slug],
                );
            }
            ++$approved;
        }

        return $approved;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $imports
     * @param array<string, list<string>>               $approvals
     */
    private function untouched(array $imports, array $approvals): int
    {
        $touched = [];
        foreach ($imports as $rows) {
            foreach ($rows as $row) {
                $touched[(string) $row['slug']] = true;
            }
        }
        foreach ($approvals as $slugs) {
            foreach ($slugs as $slug) {
                $touched[$slug] = true;
            }
        }

        $total = (int) $this->connection->fetchOne('SELECT count(*) FROM storefront_product_view')
            + (int) $this->connection->fetchOne('SELECT count(*) FROM storefront_category');

        return $total - count($touched);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function nullableDate(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
