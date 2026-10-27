<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the additional product images (a product carries up to 4 pictures).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE storefront_product_view
            ADD COLUMN additional_images JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_product_view DROP COLUMN additional_images');
    }
}
