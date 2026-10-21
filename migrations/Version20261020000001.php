<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Category visibility flag (ADR-0006) + site banner ordered image set tables (ADR-0008)';
    }

    public function up(Schema $schema): void
    {
        // 1. Category visibility (ADR-0006): hides the category and its subtree from the
        //    storefront navigation read only. Default false keeps every seeded category visible.
        $this->addSql('ALTER TABLE storefront_category ADD COLUMN hidden BOOLEAN NOT NULL DEFAULT false');

        // 2. Banner (ADR-0008): one site-level ordered image set. The single-row CHECK mirrors
        //    storefront_shipping_policy; the slide count is unbounded in the schema.
        $this->addSql('CREATE TABLE storefront_banner (
            id SMALLINT NOT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            CONSTRAINT chk_single_site_banner CHECK (id = 1)
        )');
        $this->addSql('INSERT INTO storefront_banner (id) VALUES (1)');

        $this->addSql('CREATE TABLE storefront_banner_image (
            id UUID NOT NULL,
            banner_id SMALLINT NOT NULL,
            image_path VARCHAR(512) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            CONSTRAINT fk_storefront_banner_image_banner FOREIGN KEY (banner_id) REFERENCES storefront_banner (id) ON DELETE CASCADE
        )');

        // Reads are ORDER BY sort_order ASC, created_at ASC, id ASC: sort_order is deliberately
        // NOT unique, id is the deterministic tie-break so the same set always returns in the same order.
        $this->addSql('CREATE INDEX idx_storefront_banner_image_order ON storefront_banner_image (banner_id, sort_order)');
        $this->addSql('CREATE INDEX idx_storefront_banner_image_path ON storefront_banner_image (image_path)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE storefront_banner_image');
        $this->addSql('DROP TABLE storefront_banner');
        $this->addSql('ALTER TABLE storefront_category DROP COLUMN hidden');
    }
}
