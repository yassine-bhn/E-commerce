<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * spec-run-8 — approval workflow for catalog products and categories.
 *
 * Approval and publication are two independent columns: `approval_status` is decided by the
 * Super Admin only, `published` (products) and `hidden` (categories, already there) are the
 * publication controls.
 *
 * Schema only (spec-run-12 R12, MIG-002). This migration used to end with two blanket UPDATEs that
 * approved and published every existing row; because they ran on every `up()`, a rollback followed
 * by a re-run silently re-published products a Super Admin had rejected, and a migration was writing
 * read-model data. The rows that predate the workflow are now an explicit operator step,
 * `app:catalog:backfill-approval` (dry-run by default).
 *
 * Consequence, stated in docs/runbook-deployment.md: after this migration a legacy catalogue is
 * PENDING_APPROVAL and unpublished until that command is applied.
 */
final class Version20261023000000 extends AbstractMigration
{
    private const string STATUSES = "'PENDING_APPROVAL', 'APPROVED', 'REJECTED'";

    public function getDescription(): string
    {
        return 'Approval workflow for catalog products and categories (spec-run-8)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_product_view
            ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT \'PENDING_APPROVAL\',
            ADD COLUMN submitted_by VARCHAR(64) DEFAULT NULL,
            ADD COLUMN submitted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            ADD COLUMN reviewed_by VARCHAR(64) DEFAULT NULL,
            ADD COLUMN reviewed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            ADD COLUMN rejection_reason TEXT DEFAULT NULL,
            ADD COLUMN published BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE storefront_product_view
            ADD CONSTRAINT chk_storefront_product_approval_status CHECK (approval_status IN ('.self::STATUSES.'))');
        $this->addSql('CREATE INDEX idx_storefront_product_approval ON storefront_product_view (approval_status, published)');

        $this->addSql('ALTER TABLE storefront_category
            ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT \'PENDING_APPROVAL\',
            ADD COLUMN submitted_by VARCHAR(64) DEFAULT NULL,
            ADD COLUMN submitted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            ADD COLUMN reviewed_by VARCHAR(64) DEFAULT NULL,
            ADD COLUMN reviewed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            ADD COLUMN rejection_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE storefront_category
            ADD CONSTRAINT chk_storefront_category_approval_status CHECK (approval_status IN ('.self::STATUSES.'))');
        $this->addSql('CREATE INDEX idx_storefront_category_approval ON storefront_category (approval_status)');

        // The append-only trail of every workflow action. identity_audit_log cannot carry it:
        // its target is a UUID user id, not a catalog slug (ADR-0012).
        $this->addSql('CREATE TABLE catalog_audit_log (
            id UUID NOT NULL,
            action VARCHAR(64) NOT NULL,
            target_type VARCHAR(16) NOT NULL,
            target_slug VARCHAR(128) NOT NULL,
            actor_id VARCHAR(64) NOT NULL,
            reason TEXT DEFAULT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_catalog_audit_target ON catalog_audit_log (target_type, target_slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_audit_log');

        $this->addSql('DROP INDEX idx_storefront_category_approval');
        $this->addSql('ALTER TABLE storefront_category DROP CONSTRAINT chk_storefront_category_approval_status');
        $this->addSql('ALTER TABLE storefront_category
            DROP COLUMN approval_status,
            DROP COLUMN submitted_by,
            DROP COLUMN submitted_at,
            DROP COLUMN reviewed_by,
            DROP COLUMN reviewed_at,
            DROP COLUMN rejection_reason');

        $this->addSql('DROP INDEX idx_storefront_product_approval');
        $this->addSql('ALTER TABLE storefront_product_view DROP CONSTRAINT chk_storefront_product_approval_status');
        $this->addSql('ALTER TABLE storefront_product_view
            DROP COLUMN approval_status,
            DROP COLUMN submitted_by,
            DROP COLUMN submitted_at,
            DROP COLUMN reviewed_by,
            DROP COLUMN reviewed_at,
            DROP COLUMN rejection_reason,
            DROP COLUMN published');
    }
}
