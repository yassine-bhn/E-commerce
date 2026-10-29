<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * spec-run-15 WP4 (R14) — the Inventory-owned stock balance.
 *
 * `storefront_product_view.stock_quantity` used to be the only stock record, so reservation code
 * mutated a read-model column and no write-side invariant could stop an oversell. `inventory_stock`
 * is that write model: one row per product, `on_hand` and `reserved`, with CHECK constraints that
 * keep both non-negative and `reserved <= on_hand`. The view column becomes a synchronous display
 * cache of `on_hand - reserved`.
 *
 * Schema only (MIG-002): the existing balances are initialised by
 * `app:inventory:backfill-stock`, not by this migration, so a rollback and a re-run cannot
 * re-apply an operator step.
 */
final class Version20261029000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inventory stock balance inventory_stock (spec-run-15 WP4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inventory_stock (
            product_slug VARCHAR(128) NOT NULL,
            on_hand INT NOT NULL,
            reserved INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            PRIMARY KEY (product_slug),
            CONSTRAINT chk_inventory_stock_on_hand CHECK (on_hand >= 0),
            CONSTRAINT chk_inventory_stock_reserved CHECK (reserved >= 0),
            CONSTRAINT chk_inventory_stock_available CHECK (reserved <= on_hand)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inventory_stock');
    }
}
