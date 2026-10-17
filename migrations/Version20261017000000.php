<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Outbox, catalog write/read tables, processed_events (EVT-003)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE outbox (
            id UUID NOT NULL,
            aggregate_type VARCHAR(255) NOT NULL,
            aggregate_id VARCHAR(255) NOT NULL,
            event_type VARCHAR(255) NOT NULL,
            payload JSON NOT NULL,
            occurred_on TIMESTAMP(6) WITH TIME ZONE NOT NULL,
            published_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_outbox_unpublished ON outbox (occurred_on) WHERE published_at IS NULL');

        $this->addSql('CREATE TABLE processed_events (
            consumer VARCHAR(255) NOT NULL,
            event_id UUID NOT NULL,
            processed_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(consumer, event_id)
        )');

        $this->addSql('CREATE TABLE catalog_products (
            id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            price_minor INT NOT NULL,
            currency CHAR(3) NOT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE TABLE catalog_product_view (
            id UUID NOT NULL,
            name VARCHAR(255) NOT NULL,
            price_minor INT NOT NULL,
            currency CHAR(3) NOT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE TABLE processed_commands (
            key VARCHAR(255) NOT NULL,
            PRIMARY KEY(key)
        )');

        $this->addSql('CREATE TABLE ordering_orders (
            id UUID NOT NULL,
            customer_id UUID NOT NULL,
            status VARCHAR(64) NOT NULL,
            PRIMARY KEY(id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ordering_orders');
        $this->addSql('DROP TABLE processed_commands');
        $this->addSql('DROP TABLE catalog_product_view');
        $this->addSql('DROP TABLE catalog_products');
        $this->addSql('DROP TABLE processed_events');
        $this->addSql('DROP TABLE outbox');
    }
}
