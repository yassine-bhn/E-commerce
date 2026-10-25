<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the singleton platform settings row (contact channels, auto-cancel window)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE platform_setting (
            id INT NOT NULL,
            contact_phone VARCHAR(32) NOT NULL DEFAULT \'\',
            contact_whatsapp VARCHAR(32) NOT NULL DEFAULT \'\',
            auto_cancel_minutes INT NOT NULL DEFAULT 30,
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            PRIMARY KEY(id),
            CONSTRAINT chk_platform_setting_singleton CHECK (id = 1),
            CONSTRAINT chk_platform_setting_auto_cancel CHECK (auto_cancel_minutes > 0)
        )');
        $this->addSql('INSERT INTO platform_setting (id) VALUES (1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_setting');
    }
}
