<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Business requirements schema: Order OCC versioning, proxy source, order audit trail, category parent hierarchy, identity bans and audit logs';
    }

    public function up(Schema $schema): void
    {
        // 1. Order OCC versioning, proxy channels and contact snapshot
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN version INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN order_source VARCHAR(16) NOT NULL DEFAULT \'ONLINE\'');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN customer_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN customer_phone VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN shipping_address TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN created_by_admin_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering_orders ADD COLUMN admin_notes TEXT DEFAULT NULL');

        $this->addSql('CREATE TABLE ordering_order_audit_trail (
            id UUID NOT NULL,
            order_id UUID NOT NULL,
            from_status VARCHAR(64) DEFAULT NULL,
            to_status VARCHAR(64) NOT NULL,
            from_version INT NOT NULL,
            to_version INT NOT NULL,
            changed_by VARCHAR(64) NOT NULL,
            reason TEXT NOT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_order_audit_trail_order ON ordering_order_audit_trail (order_id)');

        // 2. Category parent slug hierarchy
        $this->addSql('ALTER TABLE storefront_category ADD COLUMN parent_slug VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_category_parent ON storefront_category (parent_slug)');

        // 3. Customer role, ban governance and identity audit log
        $this->addSql('ALTER TABLE customer_profile ADD COLUMN role VARCHAR(64) NOT NULL DEFAULT \'ROLE_USER\'');
        $this->addSql('ALTER TABLE customer_profile ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT \'active\'');
        $this->addSql('ALTER TABLE customer_profile ADD COLUMN ban_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_profile ADD COLUMN banned_by VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_profile ADD COLUMN banned_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');

        $this->addSql('CREATE TABLE identity_audit_log (
            id UUID NOT NULL,
            action VARCHAR(64) NOT NULL,
            target_user_id UUID NOT NULL,
            actor_admin_id VARCHAR(64) NOT NULL,
            reason TEXT NOT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_identity_audit_target ON identity_audit_log (target_user_id)');

        // Seed initial superadmin
        $this->addSql("INSERT INTO customer_profile (id, name, email, phone, account_type, role, status, updated_at)
            VALUES (gen_random_uuid(), 'Directeur Général Zidane', 'superadmin@zidane.tn', '+216 71 000 000', 'direction', 'ROLE_SUPER_ADMIN', 'active', CURRENT_TIMESTAMP)
            ON CONFLICT DO NOTHING");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_audit_log');
        $this->addSql('ALTER TABLE customer_profile DROP COLUMN banned_at');
        $this->addSql('ALTER TABLE customer_profile DROP COLUMN banned_by');
        $this->addSql('ALTER TABLE customer_profile DROP COLUMN ban_reason');
        $this->addSql('ALTER TABLE customer_profile DROP COLUMN status');
        $this->addSql('ALTER TABLE customer_profile DROP COLUMN role');

        $this->addSql('ALTER TABLE storefront_category DROP COLUMN parent_slug');

        $this->addSql('DROP TABLE ordering_order_audit_trail');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN admin_notes');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN created_by_admin_id');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN shipping_address');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN customer_phone');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN customer_name');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN order_source');
        $this->addSql('ALTER TABLE ordering_orders DROP COLUMN version');
    }
}
