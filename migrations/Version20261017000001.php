<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Zidane storefront: product view, order lines, quote requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE storefront_product_view (
            id UUID NOT NULL,
            slug VARCHAR(128) NOT NULL,
            name VARCHAR(255) NOT NULL,
            brand VARCHAR(64) NOT NULL,
            ref VARCHAR(64) NOT NULL,
            category_slug VARCHAR(64) NOT NULL,
            subcategory VARCHAR(128) NOT NULL,
            price_minor INT NOT NULL,
            old_price_minor INT DEFAULT NULL,
            currency CHAR(3) NOT NULL,
            stock VARCHAR(16) NOT NULL,
            material VARCHAR(128) NOT NULL,
            dims VARCHAR(128) NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            rating NUMERIC(3, 1) NOT NULL,
            reviews INT NOT NULL,
            is_new BOOLEAN NOT NULL DEFAULT false,
            is_best BOOLEAN NOT NULL DEFAULT false,
            headrest BOOLEAN NOT NULL DEFAULT false,
            description TEXT NOT NULL,
            variants JSON NOT NULL DEFAULT \'[]\',
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_storefront_product_slug ON storefront_product_view (slug)');
        $this->addSql('CREATE INDEX idx_storefront_product_category ON storefront_product_view (category_slug, subcategory)');

        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN order_number VARCHAR(32) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN subtotal_minor INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN discount_minor INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN delivery_minor INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN total_minor INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN currency CHAR(3) NOT NULL DEFAULT \'TND\'');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN delivery_method VARCHAR(16) NOT NULL DEFAULT \'home\'');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN payment_method VARCHAR(64) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN promo_code VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN placed_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW()');

        $this->addSql('CREATE TABLE ordering_order_lines (
            id UUID NOT NULL,
            order_id UUID NOT NULL,
            product_slug VARCHAR(128) NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            variant_label VARCHAR(128) DEFAULT NULL,
            unit_price_minor INT NOT NULL,
            currency CHAR(3) NOT NULL,
            quantity INT NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_order_lines_order ON ordering_order_lines (order_id)');
        $this->addSql('ALTER TABLE ordering_order_lines ADD CONSTRAINT fk_order_lines_order FOREIGN KEY (order_id) REFERENCES ordering_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE identity_quote_requests (
            id UUID NOT NULL,
            user_id UUID DEFAULT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            city VARCHAR(60) DEFAULT NULL,
            category VARCHAR(64) NOT NULL,
            subcategories JSON NOT NULL DEFAULT \'[]\',
            message TEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT \'nouveau\',
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_quote_requests_user ON identity_quote_requests (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_quote_requests');
        $this->addSql('ALTER TABLE ordering_order_lines DROP CONSTRAINT fk_order_lines_order');
        $this->addSql('DROP TABLE ordering_order_lines');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN placed_at');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN promo_code');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN payment_method');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN delivery_method');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN currency');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN total_minor');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN delivery_minor');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN discount_minor');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN subtotal_minor');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN order_number');
        $this->addSql('DROP TABLE storefront_product_view');
    }
}
