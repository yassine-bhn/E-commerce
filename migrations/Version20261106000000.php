<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Additional pricing columns on the storefront product (spec-run-17 WP1 / R1, R2, R3).
 *
 * A catalog product gains a VAT rate (decimal percentage) and a sale price TTC
 * (integer minor units). Both are optional, nullable, with no backfill and no
 * default value, so every existing row keeps NULL (MIG-002).
 */
final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable vat_rate and sale_price_ttc_minor to storefront_product_view';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_product_view ADD COLUMN vat_rate NUMERIC(5,2) DEFAULT NULL');
        $this->addSql('ALTER TABLE storefront_product_view ADD COLUMN sale_price_ttc_minor INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_product_view DROP COLUMN sale_price_ttc_minor');
        $this->addSql('ALTER TABLE storefront_product_view DROP COLUMN vat_rate');
    }
}
