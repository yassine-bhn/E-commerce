<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the payment aggregate, its append-only ledger and the payment method allow-list';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment (
            id UUID NOT NULL,
            order_id UUID NOT NULL,
            method VARCHAR(64) NOT NULL,
            amount_minor INT NOT NULL,
            currency CHAR(3) NOT NULL,
            status VARCHAR(16) NOT NULL,
            version INT NOT NULL DEFAULT 1,
            provider_reference VARCHAR(128) DEFAULT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            PRIMARY KEY(id),
            CONSTRAINT uniq_payment_order UNIQUE (order_id),
            CONSTRAINT chk_payment_amount CHECK (amount_minor > 0),
            CONSTRAINT chk_payment_version CHECK (version > 0)
        )');

        $this->addSql('CREATE TABLE payment_ledger (
            id UUID NOT NULL,
            payment_id UUID NOT NULL,
            from_status VARCHAR(16) DEFAULT NULL,
            to_status VARCHAR(16) NOT NULL,
            version INT NOT NULL,
            amount_minor INT NOT NULL,
            actor_id VARCHAR(64) NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL DEFAULT NOW(),
            PRIMARY KEY(id),
            CONSTRAINT fk_payment_ledger_payment FOREIGN KEY (payment_id) REFERENCES payment (id)
        )');
        $this->addSql('CREATE INDEX idx_payment_ledger_payment ON payment_ledger (payment_id, created_at)');

        // O-19 — the operator decides which methods checkout accepts; the default keeps every
        // method the backend already receives, so no existing caller breaks (assumption A7).
        $this->addSql('ALTER TABLE platform_setting ADD COLUMN payment_methods JSON NOT NULL DEFAULT \'["Carte bancaire","Virement / Paiement à la livraison","Espèces à la livraison"]\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_setting DROP COLUMN payment_methods');
        $this->addSql('DROP TABLE payment_ledger');
        $this->addSql('DROP TABLE payment');
    }
}
