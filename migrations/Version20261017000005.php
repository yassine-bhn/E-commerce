<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password_hash to customer_profile for session authentication.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_profile ADD password_hash VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_profile DROP password_hash');
    }
}
