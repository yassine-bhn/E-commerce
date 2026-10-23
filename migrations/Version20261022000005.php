<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the pending-order scan used by the auto-cancel command';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_ordering_orders_status_placed ON ordering_orders (status, placed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ordering_orders_status_placed');
    }
}
