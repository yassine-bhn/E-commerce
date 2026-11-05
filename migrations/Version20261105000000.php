<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Optional supplier reference on the storefront product (spec-run-16 R1, MIG-001).
 *
 * The customer-facing `ref` is untouched. The supplier's own reference is an admin-only,
 * nullable free-text field, so every existing row keeps NULL and no backfill runs (MIG-002).
 */
final class Version20261105000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable supplier_reference to storefront_product_view';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_product_view ADD COLUMN supplier_reference VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_product_view DROP COLUMN supplier_reference');
    }
}
