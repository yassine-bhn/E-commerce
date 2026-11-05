<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * spec-run-15 WP2 (R1/R2) — the approval write model's table.
 *
 * Approval used to live only in `storefront_product_view` and `storefront_category`, so a view
 * rebuild lost the authoritative state and no single writer could refuse an illegal transition.
 * `catalog_submission` is that authority: one row per `(kind, slug)`, the five workflow moments and
 * the status, with the CHECK constraints that keep `kind` and `approval_status` to the values the
 * domain enums actually produce.
 *
 * Schema only (MIG-002): the legacy rows are seeded by `app:catalog:backfill-approval` in WP3, not
 * by this migration, so a rollback and a re-run cannot silently re-apply an operator decision.
 */
final class Version20261029000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Approval write model catalog_submission (spec-run-15 WP2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE catalog_submission (
            kind VARCHAR(16) NOT NULL,
            slug VARCHAR(128) NOT NULL,
            approval_status VARCHAR(20) NOT NULL DEFAULT 'PENDING_APPROVAL',
            submitted_by VARCHAR(64) DEFAULT NULL,
            submitted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            reviewed_by VARCHAR(64) DEFAULT NULL,
            reviewed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            rejection_reason TEXT DEFAULT NULL,
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            PRIMARY KEY (kind, slug),
            CONSTRAINT chk_catalog_submission_kind CHECK (kind IN ('products', 'categories')),
            CONSTRAINT chk_catalog_submission_status
                CHECK (approval_status IN ('PENDING_APPROVAL', 'APPROVED', 'REJECTED'))
        )");
        $this->addSql('CREATE INDEX idx_catalog_submission_status ON catalog_submission (kind, approval_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_submission');
    }
}
