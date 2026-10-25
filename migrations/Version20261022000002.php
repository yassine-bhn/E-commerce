<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable stock quantity to the storefront product view';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_product_view ADD COLUMN stock_quantity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE storefront_product_view ADD CONSTRAINT chk_storefront_product_stock_quantity_non_negative CHECK (stock_quantity IS NULL OR stock_quantity >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_product_view DROP CONSTRAINT chk_storefront_product_stock_quantity_non_negative');
        $this->addSql('ALTER TABLE storefront_product_view DROP COLUMN stock_quantity');
    }
}
