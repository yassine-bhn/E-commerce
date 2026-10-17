<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Infrastructure\Outbox\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:relay', description: 'Publish outbox messages to event.bus')]
final class OutboxRelayCommand extends Command
{
    public function __construct(private OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $published = $this->relay->publishBatch();
        $output->writeln(sprintf('Published %d outbox message(s).', $published));

        return Command::SUCCESS;
    }
}
