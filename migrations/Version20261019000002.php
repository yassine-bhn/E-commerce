<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link customer_profile to Google OAuth accounts via google_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_profile ADD google_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_profile_google_id ON customer_profile (google_id) WHERE google_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_customer_profile_google_id');
        $this->addSql('ALTER TABLE customer_profile DROP google_id');
    }
}
