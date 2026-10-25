<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set the existing superadmin account password by email.';
    }

    public function up(Schema $schema): void
    {
        $matchingAccounts = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM customer_profile WHERE email = :email',
            ['email' => 'superadmin@zidane.tn'],
        );

        $this->abortIf(
            1 !== $matchingAccounts,
            sprintf(
                'Expected exactly one customer_profile for superadmin@zidane.tn, found %d. No row was changed.',
                $matchingAccounts,
            ),
        );

        $this->addSql(
            'UPDATE customer_profile
                SET password_hash = :password_hash,
                    updated_at = CURRENT_TIMESTAMP
              WHERE email = :email',
            [
                'password_hash' => '$2y$12$OGpbU3sGhVbr/AbhfHdgM.fPlo8jAlwzk80.cpRUGi8OvDxdhhUru',
                'email' => 'superadmin@zidane.tn',
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
