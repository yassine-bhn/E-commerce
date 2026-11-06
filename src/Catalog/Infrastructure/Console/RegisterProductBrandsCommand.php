<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Console;

use App\Catalog\Application\Port\CatalogSubmissionCreator;
use App\Catalog\Application\Port\CatalogSubmissionReviewer;
use App\Catalog\Domain\Model\CatalogSubmissionKind;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers the brands products already carry but that were never created through the Marques
 * screen (the CBM regression): the missing `storefront_brand` rows, approved through the
 * submission workflow, so the admin list, the storefront filters and renames all see them.
 *
 * Dry-run by default (MIG-002): no read-model row is written by a migration; this is the
 * explicit operator step.
 */
#[AsCommand(
    name: 'app:catalog:register-product-brands',
    description: 'Register the brands products carry but that are missing from storefront_brand (dry-run by default)',
)]
final class RegisterProductBrandsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogSubmissionCreator $submissions,
        private readonly CatalogSubmissionReviewer $reviews,
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

        $brands = $this->missingBrands();

        if (!$apply) {
            $output->writeln(sprintf(
                '<comment>Dry run: would register %d brand(s)%s; %d brand(s) already present.</comment>',
                count($brands),
                [] === $brands ? '' : ' ('.implode(', ', $brands).')',
                $this->presentBrandCount(),
            ));
            $output->writeln('Nothing was written. Re-run with --apply to apply the registration.');

            return Command::SUCCESS;
        }

        $registered = 0;
        $this->connection->transactional(function () use ($brands, &$registered): void {
            foreach ($brands as $brand) {
                $this->connection->executeStatement(
                    'INSERT INTO storefront_brand (name) VALUES (:name) ON CONFLICT (name) DO NOTHING',
                    ['name' => $brand],
                );

                $this->submissions->create(CatalogSubmissionKind::Brand, $brand, 'system:register-product-brands');
                $this->reviews->reviewerSave(CatalogSubmissionKind::Brand, $brand, 'system:register-product-brands');
                ++$registered;
            }
        });

        $output->writeln(sprintf('Registered %d brand(s).', $registered));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function missingBrands(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT p.brand
               FROM storefront_product_view p
              WHERE p.brand <> ''
                AND NOT EXISTS (
                    SELECT 1 FROM storefront_brand b WHERE lower(b.name) = lower(p.brand)
                )
              ORDER BY p.brand ASC",
        );

        return array_map('strval', $rows);
    }

    private function presentBrandCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM storefront_brand');
    }
}
