<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Logged-in customer storefront carts and line items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE storefront_cart (
            id UUID NOT NULL,
            customer_id UUID NOT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            CONSTRAINT uniq_storefront_cart_customer UNIQUE (customer_id)
        )');
        $this->addSql('CREATE INDEX idx_storefront_cart_customer ON storefront_cart (customer_id)');

        $this->addSql('CREATE TABLE storefront_cart_item (
            id UUID NOT NULL,
            cart_id UUID NOT NULL,
            product_slug VARCHAR(255) NOT NULL,
            variant_label VARCHAR(255) DEFAULT NULL,
            quantity INT NOT NULL,
            unit_price_minor INT NOT NULL,
            currency CHAR(3) NOT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            CONSTRAINT fk_storefront_cart_item_cart FOREIGN KEY (cart_id) REFERENCES storefront_cart (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_storefront_cart_item_cart ON storefront_cart_item (cart_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_storefront_cart_item_line ON storefront_cart_item (cart_id, product_slug, COALESCE(variant_label, \'\'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE storefront_cart_item');
        $this->addSql('DROP TABLE storefront_cart');
    }
}
