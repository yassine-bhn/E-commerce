<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Console;

use App\Catalog\Infrastructure\Search\StorefrontProductSearchIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds the whole `storefront_products` index from `storefront_product_view`.
 *
 * Unlike the write paths, an explicit reindex must be loud: when Meilisearch is unreachable
 * the command exits non-zero instead of leaving an empty index behind silently.
 */
#[AsCommand(name: 'app:storefront:index-search', description: 'Rebuild Meilisearch index from storefront_product_view')]
final class IndexStorefrontSearchCommand extends Command
{
    public function __construct(private StorefrontProductSearchIndexer $indexer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $indexed = $this->indexer->rebuildAll();
        } catch (\Throwable $exception) {
            $output->writeln('<error>Reindex failed: '.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Indexed %d storefront products.', $indexed));

        return Command::SUCCESS;
    }
}
