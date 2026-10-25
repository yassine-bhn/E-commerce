<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes `customer_profile.email` unique case-insensitively (spec-run-12 R7-R9).
 *
 * Every identity lookup compares `lower(email)` — registration, Google linking, login — so the
 * constraint has to be a functional unique index on the same expression. A plain unique index would
 * leave `A@x.com` and `a@x.com` registrable and still let two accounts answer one address.
 *
 * The migration changes no row: it refuses to run when duplicates already exist, because the only
 * alternatives are deleting an account or silently merging two identities (MIG-001, no data loss).
 * The message names the duplicated addresses only in masked form — a migration log is archived with
 * the release, so it is not a place for a customer's address — and prints the query that finds them.
 *
 * The index is built inside the migration's transaction, which holds SHARE on `customer_profile` for
 * the build. That blocks registration writes, so the bound matters: the build is sized for a table
 * that stays small. If `customer_profile` ever reaches a row count where that pause is unacceptable,
 * this becomes `isTransactional() === false` plus `CREATE UNIQUE INDEX CONCURRENTLY`, with the
 * documented cost that a failed concurrent build leaves an INVALID index to drop and rebuild.
 *
 * down() restores the plain index that Version20261022000000 installs, so that migration's down()
 * still finds `idx_customer_profile_email` and a full rollback keeps working.
 */
final class Version20261024000000 extends AbstractMigration
{
    private const string UNIQUE_INDEX = 'uniq_customer_profile_email_lower';

    private const string PLAIN_INDEX = 'idx_customer_profile_email';

    public function getDescription(): string
    {
        return 'Unique lower(email) index on customer_profile, refusing to run on existing duplicates';
    }

    public function up(Schema $schema): void
    {
        $duplicates = $this->duplicateEmails();

        $this->abortIf(
            [] !== $duplicates,
            sprintf(
                'customer_profile holds %d e-mail address(es) shared by more than one account, so it '
                ."cannot be made unique case-insensitively: %s.\n"
                .'No row was changed. To find them, run: '
                ."SELECT lower(email), count(*) FROM customer_profile GROUP BY lower(email) HAVING count(*) > 1.\n"
                .'Decide for each address which account keeps it (merge or re-address the others), then '
                .'run this migration again.',
                count($duplicates),
                self::summarise($duplicates),
            ),
        );

        $this->addSql('DROP INDEX '.self::PLAIN_INDEX);
        $this->addSql(sprintf('CREATE UNIQUE INDEX %s ON customer_profile (lower(email))', self::UNIQUE_INDEX));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX '.self::UNIQUE_INDEX);
        $this->addSql(sprintf('CREATE INDEX %s ON customer_profile (email)', self::PLAIN_INDEX));
    }

    /**
     * Read-only pre-check: the addresses the constraint would reject, newest duplicates included so
     * the operator sees how widespread the problem is without opening a second tool.
     *
     * @return list<string>
     */
    private function duplicateEmails(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT lower(email) AS address, count(*) AS accounts
               FROM customer_profile
              GROUP BY lower(email)
             HAVING count(*) > 1
              ORDER BY count(*) DESC, lower(email) ASC',
        );

        return array_map(
            static fn (array $row): string => sprintf('%s (%d accounts)', self::mask((string) $row['address']), (int) $row['accounts']),
            $rows,
        );
    }

    /**
     * Enough of the address to recognise it, none of it enough to reuse: the local part keeps its
     * first character, the domain stays because it is what tells two look-alike rows apart.
     */
    private static function mask(string $address): string
    {
        $at = strrpos($address, '@');
        if (false === $at) {
            return str_repeat('*', mb_strlen($address));
        }

        $local = mb_substr($address, 0, $at);

        return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).mb_substr($address, $at);
    }

    /**
     * @param list<string> $duplicates
     */
    private static function summarise(array $duplicates): string
    {
        $sample = array_slice($duplicates, 0, 5);
        $more = count($duplicates) - count($sample);

        return implode('; ', $sample).($more > 0 ? sprintf(' and %d more', $more) : '');
    }
}
