<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer password reset tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_password_reset_token (
            id UUID NOT NULL,
            customer_id UUID NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
            used_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_customer_password_reset_token_hash ON customer_password_reset_token (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE customer_password_reset_token');
    }
}
