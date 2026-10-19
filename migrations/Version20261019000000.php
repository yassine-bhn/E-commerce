<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer wishlist items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE storefront_wishlist_item (
            id UUID NOT NULL,
            customer_id UUID NOT NULL,
            product_slug VARCHAR(255) NOT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            CONSTRAINT uniq_storefront_wishlist_item_customer_slug UNIQUE (customer_id, product_slug)
        )');
        $this->addSql('CREATE INDEX idx_storefront_wishlist_item_customer ON storefront_wishlist_item (customer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE storefront_wishlist_item');
    }
}
