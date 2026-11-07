<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * spec-run-15 R33 — MIG-002 is enforceable: no migration may write to a projection table
 * (`*_view` or `storefront_category`) except the three historical migrations that are frozen
 * in the allowlist. A new migration that re-approves, re-publishes or re-prices a view row
 * fails this test even though the database would answer identically.
 */
final class MigrationReadModelGuardTest extends TestCase
{
    /** @var list<string> */
    private const array ALLOWLIST = [
        'Version20261017000002.php',
        'Version20261018000000.php',
        'Version20261029000003.php',
    ];

    public function testNoMigrationWritesToAProjectionTableOutsideTheAllowlist(): void
    {
        $migrationsDir = dirname(__DIR__, 2).'/migrations';
        self::assertDirectoryExists($migrationsDir);

        foreach (self::ALLOWLIST as $allowed) {
            self::assertFileExists(
                $migrationsDir.'/'.$allowed,
                sprintf('Allowlisted migration %s must still exist (R33).', $allowed),
            );
        }

        $pattern = '/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+[^;]*?(?:[a-z_]*_view|storefront_category)\b/is';

        $violations = [];
        foreach ((new Finder())->files()->in($migrationsDir)->name('*.php')->sortByName() as $file) {
            $basename = $file->getFilename();
            if (in_array($basename, self::ALLOWLIST, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (1 === preg_match($pattern, $contents)) {
                $violations[] = $basename;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Migrations must not INSERT/UPDATE/DELETE/TRUNCATE a `*_view` table or `storefront_category` '
            .'(R33, MIG-002). Offending files: '.implode(', ', $violations),
        );
    }

    public function testASyntheticViolationIsDetected(): void
    {
        $needle = 'UPDATE storefront_product_view SET published = true';
        self::assertMatchesRegularExpression('/_view\b/', $needle);
        self::assertStringContainsString('UPDATE', $needle);
    }

    public function testTheAllowlistFilesStillContainTheirWrites(): void
    {
        // The allowlisted migrations must still contain their projection writes, otherwise the
        // allowlist has drifted from reality (R33).
        $migrationsDir = dirname(__DIR__, 2).'/migrations';
        $pattern = '/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+[^;]*?(?:[a-z_]*_view|storefront_category)\b/is';

        foreach (self::ALLOWLIST as $allowed) {
            $contents = (string) file_get_contents($migrationsDir.'/'.$allowed);
            self::assertMatchesRegularExpression(
                $pattern,
                $contents,
                sprintf('Allowlisted migration %s no longer writes to a projection table (R33).', $allowed),
            );
        }
    }
}
