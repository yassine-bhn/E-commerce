<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Approves and publishes the catalogue rows that existed before the approval workflow (spec-run-12
 * R13, MIG-002).
 *
 * The migration used to do this with two blanket UPDATEs, which is exactly what MIG-002 forbids: a
 * migration that writes read-model rows re-applies the same write on every `up()`, so a rollback
 * followed by a re-run silently re-published products a Super Admin had rejected. The schema change
 * stays in the migration; the data change is this explicit, dry-run-by-default operator step.
 *
 * The selection is "no workflow metadata at all", not a date: neither view table has a created_at, so
 * a row that was submitted or reviewed is the only thing that can be told apart from a legacy one.
 * The row must also still be PENDING_APPROVAL: an approved row a Super Admin later unpublished is
 * somebody's deliberate decision, and re-publishing it on the next release would be the same class
 * of silent write this command exists to remove. That combination is what makes a second run write
 * nothing and report zero.
 *
 * It writes the trail too. Approving without an entry would leave the one path into `APPROVED` that
 * `catalog_audit_log` cannot explain (ADR-0012), so the backfill records itself as
 * `system:backfill-approval` in the same transaction as the state change.
 */
#[AsCommand(
    name: 'app:catalog:backfill-approval',
    description: 'Approve and publish the catalogue rows that predate the approval workflow (dry-run by default)',
)]
final class BackfillCatalogApprovalCommand extends Command
{
    /** A row that carries none of this has never been through the workflow. */
    private const string LEGACY_ROW =
        'submitted_by IS NULL AND submitted_at IS NULL AND reviewed_by IS NULL AND reviewed_at IS NULL';

    /** No human decided these rows; the trail names the release step that did. */
    private const string ACTOR = 'system:backfill-approval';

    public function __construct(private readonly Connection $connection)
    {
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

        // R13 asks for both halves of the report: what the step changed, and what it left alone. The
        // second number is the operator's only signal that the one-off step is complete.
        $products = $this->count($this->pendingProducts());
        $categories = $this->count($this->pendingCategories());
        $untouched = $this->countNumberOf('storefront_product_view') + $this->countNumberOf('storefront_category')
            - $products - $categories;

        if (!$apply) {
            $output->writeln(sprintf(
                '<comment>Dry run: %d product(s) and %d category/categories would be approved and published; %d row(s) left untouched.</comment>',
                $products,
                $categories,
                $untouched,
            ));
            $output->writeln('Nothing was written. Re-run with --apply to apply the backfill.');

            return Command::SUCCESS;
        }

        [$approvedProducts, $approvedCategories] = $this->connection->transactional(function (): array {
            // The trail first: its predicate is the same PENDING_APPROVAL state the UPDATE consumes,
            // so each row is explained exactly once, in the same transaction as the change itself.
            $this->connection->executeStatement($this->trailProducts(), ['actor' => self::ACTOR]);
            $this->connection->executeStatement($this->trailCategories(), ['actor' => self::ACTOR]);

            return [
                $this->connection->executeStatement($this->approveProducts()),
                $this->connection->executeStatement($this->approveCategories()),
            ];
        });

        $output->writeln(sprintf(
            'Approved %d product(s) (published) and %d category/categories; %d row(s) left untouched.',
            $approvedProducts,
            $approvedCategories,
            $untouched,
        ));

        if ($approvedProducts > 0) {
            $output->writeln('The search index only holds visible products: run "app:storefront:index-search" to publish them to search.');
        }

        return Command::SUCCESS;
    }

    private function count(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }

    private function countNumberOf(string $table): int
    {
        return $this->count('SELECT count(*) FROM '.$table);
    }

    private function pendingProducts(): string
    {
        return sprintf(
            "SELECT count(*) FROM storefront_product_view WHERE %s AND approval_status = 'PENDING_APPROVAL'",
            self::LEGACY_ROW,
        );
    }

    private function pendingCategories(): string
    {
        return sprintf(
            "SELECT count(*) FROM storefront_category WHERE %s AND approval_status = 'PENDING_APPROVAL'",
            self::LEGACY_ROW,
        );
    }

    private function approveProducts(): string
    {
        return sprintf(
            "UPDATE storefront_product_view SET approval_status = 'APPROVED', published = true WHERE %s AND approval_status = 'PENDING_APPROVAL'",
            self::LEGACY_ROW,
        );
    }

    private function approveCategories(): string
    {
        return sprintf(
            "UPDATE storefront_category SET approval_status = 'APPROVED' WHERE %s AND approval_status = 'PENDING_APPROVAL'",
            self::LEGACY_ROW,
        );
    }

    private function trailProducts(): string
    {
        return $this->trailFor('product', 'storefront_product_view');
    }

    private function trailCategories(): string
    {
        return $this->trailFor('category', 'storefront_category');
    }

    /**
     * One audit entry per row about to be approved, written before the state changes so the predicate
     * is the same PENDING_APPROVAL state — a second run finds neither rows nor trail entries.
     */
    private function trailFor(string $targetType, string $table): string
    {
        return sprintf(
            "INSERT INTO catalog_audit_log (id, action, target_type, target_slug, actor_id, reason, created_at)
             SELECT gen_random_uuid(), 'CATALOG_SUBMISSION_APPROVED', '%s', slug, :actor,
                    'approved by the release backfill of rows that predate the workflow', now()
               FROM %s
              WHERE %s AND approval_status = 'PENDING_APPROVAL'",
            $targetType,
            $table,
            self::LEGACY_ROW,
        );
    }
}
