<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Brands join the catalog approval workflow (the same lifecycle as products and categories).
 *
 * `storefront_brand` receives the six approval columns the workflow projects, keyed by the
 * brand `name` — the one catalog table without a slug. Schema only (spec-run-12 R12, MIG-002):
 * the seeded and legacy brands stay PENDING_APPROVAL until the operator applies
 * `app:catalog:backfill-approval`, which imports and approves them like every other
 * pre-workflow row. Until then the storefront brand filter lists nothing, exactly like the
 * products and categories documented in docs/runbook-deployment.md.
 *
 * The `catalog_submission` kind CHECK is widened in the same migration, because a brand write
 * would otherwise be refused by a constraint the code cannot see.
 */
final class Version20261101000000 extends AbstractMigration
{
    private const string STATUSES = "'PENDING_APPROVAL', 'APPROVED', 'REJECTED'";

    public function getDescription(): string
    {
        return 'Approval workflow columns for storefront_brand and the brands submission kind';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_brand
            ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT \'PENDING_APPROVAL\',
            ADD COLUMN submitted_by VARCHAR(64) DEFAULT NULL,
            ADD COLUMN submitted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            ADD COLUMN reviewed_by VARCHAR(64) DEFAULT NULL,
            ADD COLUMN reviewed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            ADD COLUMN rejection_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE storefront_brand
            ADD CONSTRAINT chk_storefront_brand_approval_status CHECK (approval_status IN ('.self::STATUSES.'))');
        $this->addSql('CREATE INDEX idx_storefront_brand_approval ON storefront_brand (approval_status)');

        $this->addSql('ALTER TABLE catalog_submission DROP CONSTRAINT chk_catalog_submission_kind');
        $this->addSql("ALTER TABLE catalog_submission
            ADD CONSTRAINT chk_catalog_submission_kind CHECK (kind IN ('products', 'categories', 'brands'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_submission DROP CONSTRAINT chk_catalog_submission_kind');
        $this->addSql("ALTER TABLE catalog_submission
            ADD CONSTRAINT chk_catalog_submission_kind CHECK (kind IN ('products', 'categories'))");

        $this->addSql('DROP INDEX idx_storefront_brand_approval');
        $this->addSql('ALTER TABLE storefront_brand DROP CONSTRAINT chk_storefront_brand_approval_status');
        $this->addSql('ALTER TABLE storefront_brand
            DROP COLUMN approval_status,
            DROP COLUMN submitted_by,
            DROP COLUMN submitted_at,
            DROP COLUMN reviewed_by,
            DROP COLUMN reviewed_at,
            DROP COLUMN rejection_reason');
    }
}
