<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Meilisearch\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:storefront:index-search', description: 'Rebuild Meilisearch index from storefront_product_view')]
final class IndexStorefrontSearchCommand extends Command
{
    public function __construct(
        private Connection $connection,
        #[Autowire(env: 'MEILISEARCH_URL')]
        private string $meilisearchUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT slug, name, brand, ref, subcategory, category_slug, price_minor FROM storefront_product_view',
        );

        $documents = array_map(static fn (array $row): array => [
            'id' => $row['slug'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'brand' => $row['brand'],
            'ref' => $row['ref'],
            'sub' => $row['subcategory'],
            'category' => $row['category_slug'],
            'price_minor' => (int) $row['price_minor'],
        ], $rows);

        $client = new Client($this->meilisearchUrl);
        $index = $client->index('storefront_products');
        $index->deleteAllDocuments();
        if ([] !== $documents) {
            $index->addDocuments($documents, 'id');
        }

        $output->writeln(sprintf('Indexed %d storefront products.', count($documents)));

        return Command::SUCCESS;
    }
}
