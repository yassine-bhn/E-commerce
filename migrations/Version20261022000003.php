<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the inventory reservation log used by checkout';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inventory_reservation (
            order_id UUID NOT NULL,
            product_slug VARCHAR(128) NOT NULL,
            quantity INT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'reserved\',
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            released_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            PRIMARY KEY(order_id, product_slug),
            CONSTRAINT chk_inventory_reservation_quantity CHECK (quantity > 0),
            CONSTRAINT chk_inventory_reservation_status CHECK (status IN (\'reserved\', \'released\'))
        )');
        $this->addSql('CREATE INDEX idx_inventory_reservation_slug ON inventory_reservation (product_slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inventory_reservation');
    }
}
