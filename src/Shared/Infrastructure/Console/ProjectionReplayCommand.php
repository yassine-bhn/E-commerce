<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Infrastructure\Projection\ProjectionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/console projection:replay <name>` — the operator entry point for rebuildable views
 * (spec-run-15 R30/R31, H6, MIG-002).
 *
 * The command owns only the console contract: a required name, `--dry-run`, a non-zero exit for an
 * unknown name and the supported-name list that makes the failure actionable. How a projection is
 * rebuilt lives in the tagged replayer, so the command never names a context or a table.
 */
#[AsCommand(
    name: 'projection:replay',
    description: 'Rebuild a read-model projection from its state-based source (MIG-002)',
)]
final class ProjectionReplayCommand extends Command
{
    public function __construct(private readonly ProjectionRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'Projection replay name; an unknown name lists the registered names',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report the rows that would be written without writing anything',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = \is_string($name) ? $name : '';

        $replayer = $this->registry->find($name);
        if (null === $replayer) {
            $names = $this->registry->names();
            $output->writeln(sprintf(
                '<error>Unknown projection "%s". Supported replays: %s.</error>',
                $name,
                [] === $names ? '(none registered)' : implode(', ', $names),
            ));

            return Command::FAILURE;
        }

        $dryRun = true === $input->getOption('dry-run');
        $report = $replayer->replay($dryRun);
        $missingView = $report['missingView'] ?? 0;

        if ($dryRun) {
            $output->writeln(sprintf(
                'Dry run: %s would write %d row(s) from %s; %d orphan(s) reported. Nothing was written.',
                $replayer->name(),
                $report['written'],
                $replayer->source(),
                $report['orphans'],
            ));

            $this->reportMissingViews($output, $missingView);

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'Replayed %s from %s: %d row(s) written; %d orphan(s) left in place.',
            $replayer->name(),
            $replayer->source(),
            $report['written'],
            $report['orphans'],
        ));

        $this->reportMissingViews($output, $missingView);

        return Command::SUCCESS;
    }

    private function reportMissingViews(OutputInterface $output, int $missingView): void
    {
        if ($missingView <= 0) {
            return;
        }

        $output->writeln(sprintf(
            '%d missing view(s): a source row has no matching view row. Nothing was inserted.',
            $missingView,
        ));
    }
}
