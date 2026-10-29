<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Console;

use App\Inventory\Domain\Model\StockLevel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * spec-run-15 WP4 (R18) — initialise `inventory_stock` from the quantity the view still holds.
 *
 * Before this work `storefront_product_view.stock_quantity` was the only balance, while active
 * `inventory_reservation` rows recorded the units orders already hold. The backfill reconstructs
 * the authoritative pair without changing what a customer can buy:
 * `reserved = SUM(active reservation quantities)` and `on_hand = stock_quantity + reserved`, so
 * `on_hand - reserved` is exactly today's `stock_quantity`.
 *
 * It is an operator step, not a migration (MIG-002): dry-run by default, writes only with
 * `--apply`, one transaction, `ON CONFLICT DO NOTHING`, and a second run writes nothing. Untracked
 * products (`stock_quantity IS NULL`) are skipped entirely, and a tracked row whose computed values
 * cannot satisfy the table's checks or the INT column range is reported instead of inserted.
 */
#[AsCommand(
    name: 'app:inventory:backfill-stock',
    description: 'Create the missing inventory_stock balances from the view cache (dry-run by default)',
)]
final class BackfillInventoryStockCommand extends Command
{
    /** PostgreSQL `INT` is 32-bit; an on_hand beyond it cannot be stored. */
    private const int MAX_INT = 2_147_483_647;

    private const string CANDIDATES_SQL = <<<'SQL'
        SELECT v.slug,
               v.stock_quantity,
               COALESCE((
                   SELECT SUM(r.quantity)
                     FROM inventory_reservation r
                    WHERE r.product_slug = v.slug AND r.status = 'reserved'
               ), 0) AS reserved
          FROM storefront_product_view v
          LEFT JOIN inventory_stock s ON s.product_slug = v.slug
         WHERE v.stock_quantity IS NOT NULL
           AND s.product_slug IS NULL
         ORDER BY v.slug
        SQL;

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
            'Write the balances; without it the command only reports what it would do',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = true === $input->getOption('apply');

        /** @var list<array{slug: string, on_hand: int, reserved: int}> $initialisable */
        $initialisable = [];
        $unsafe = 0;

        /** @var list<array{slug: string, stock_quantity: int|string, reserved: int|string}> $candidates */
        $candidates = $this->connection->fetchAllAssociative(self::CANDIDATES_SQL);
        foreach ($candidates as $candidate) {
            $reserved = (int) $candidate['reserved'];
            $onHand = (int) $candidate['stock_quantity'] + $reserved;

            if ($onHand > self::MAX_INT || $reserved > self::MAX_INT) {
                ++$unsafe;

                continue;
            }

            try {
                new StockLevel($onHand, $reserved);
            } catch (\InvalidArgumentException) {
                ++$unsafe;

                continue;
            }

            $initialisable[] = ['slug' => $candidate['slug'], 'on_hand' => $onHand, 'reserved' => $reserved];
        }

        if (!$apply) {
            $output->writeln(sprintf(
                '<comment>Dry run: %d inventory_stock row(s) would be created; %d tracked row(s) cannot be initialised safely. Nothing was written.</comment>',
                \count($initialisable),
                $unsafe,
            ));
            $output->writeln('Re-run with --apply to create the balances.');

            return Command::SUCCESS;
        }

        $created = $this->connection->transactional(function () use ($initialisable): int {
            $created = 0;
            foreach ($initialisable as $row) {
                $created += (int) $this->connection->executeStatement(
                    'INSERT INTO inventory_stock (product_slug, on_hand, reserved)
                     VALUES (:slug, :on_hand, :reserved)
                     ON CONFLICT (product_slug) DO NOTHING',
                    ['slug' => $row['slug'], 'on_hand' => $row['on_hand'], 'reserved' => $row['reserved']],
                );
            }

            return $created;
        });

        $output->writeln(sprintf(
            'Created %d inventory_stock row(s); %d tracked row(s) cannot be initialised safely.',
            $created,
            $unsafe,
        ));

        return Command::SUCCESS;
    }
}
