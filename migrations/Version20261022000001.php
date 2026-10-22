<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * R10 — `GET /api/v1/admin/customers` documents a `createdAt` sort and the account table only had
 * `updated_at`. Adds the creation timestamp (best-effort: existing rows take the migration time)
 * with the matching index, and drops both on rollback (MIG-001). No row is rewritten (MIG-002).
 */
final class Version20261022000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer account creation timestamp backing the dashboard list createdAt sort';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_profile ADD COLUMN created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('CREATE INDEX idx_customer_profile_created_at ON customer_profile (created_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_customer_profile_created_at');
        $this->addSql('ALTER TABLE customer_profile DROP COLUMN created_at');
    }
}
