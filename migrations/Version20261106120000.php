<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Brand image reference on the storefront brand (spec-run-19 WP1 / R1).
 *
 * A brand gains one nullable image path, the same single-optional-image shape
 * as the product's `image_path`. Schema only (MIG-002): nullable, no backfill
 * and no default, so every existing row keeps NULL and reads back as an
 * imageless brand until an image is attached through the admin API.
 */
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable image_path to storefront_brand';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_brand ADD COLUMN image_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storefront_brand DROP COLUMN image_path');
    }
}
