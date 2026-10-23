<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Console;

use App\Ordering\Application\Command\AutoCancelOrder;
use App\Ordering\Domain\Model\OptimisticLockException;
use App\Ordering\Domain\Model\OrderStatus;
use App\Shared\Infrastructure\ReadModel\PlatformSettingsRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:orders:auto-cancel', description: 'Cancel pending orders older than the timeout and release their stock')]
final class AutoCancelExpiredOrdersCommand extends Command
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $commandBus,
        private PlatformSettingsRepository $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('timeout-minutes', null, InputOption::VALUE_REQUIRED, 'Pending orders older than this many minutes are cancelled. Defaults to the platform setting.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of orders cancelled per run.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeout = $this->timeoutMinutes($input);
        $limit = max(1, (int) $input->getOption('limit'));

        $ids = $this->connection->fetchFirstColumn(
            "SELECT id FROM ordering_orders
             WHERE status = :pending
               AND placed_at < NOW() - (:timeout * INTERVAL '1 minute')
             ORDER BY placed_at
             LIMIT ".$limit,
            ['pending' => OrderStatus::PENDING->value, 'timeout' => $timeout],
        );

        $cancelled = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            try {
                $this->commandBus->dispatch(new AutoCancelOrder((string) $id));
                ++$cancelled;
            } catch (HandlerFailedException $e) {
                $cause = $this->rootCause($e);
                if ($cause instanceof OptimisticLockException) {
                    ++$skipped;
                    continue;
                }

                throw $e;
            }
        }

        $output->writeln(sprintf('Auto-cancel: %d cancelled, %d skipped.', $cancelled, $skipped));

        return Command::SUCCESS;
    }

    /**
     * The operator-set window is the default; an explicit option still wins for one-off runs.
     */
    private function timeoutMinutes(InputInterface $input): int
    {
        $option = $input->getOption('timeout-minutes');
        if (null === $option || '' === $option) {
            return max(1, $this->settings->get()['autoCancelMinutes']);
        }

        return max(1, (int) $option);
    }

    private function rootCause(\Throwable $e): \Throwable
    {
        while ($e instanceof HandlerFailedException && null !== $e->getPrevious()) {
            $e = $e->getPrevious();
        }

        return $e;
    }
}
