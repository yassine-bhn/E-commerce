<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * spec-run-12 R1/R2 — the three design properties the approval-workflow report left at INSPECTED,
 * each turned into an assertion that fails when the property stops holding.
 *
 * R4/R10 — "no second approval mechanism". After WP3 the write model is the authority: the decision
 * type reaches persistence only through the enum that defines it, the command that carries it, the
 * port and adapter that store it (the port is a WP3 deviation: its typed `decide()` signature must
 * name the decision), the aggregate that applies it, the controller that builds the command and the
 * operator backfill that replays it. `catalog_submission` is written by one adapter only, and the
 * six approval columns of the two view tables are written by the two projections only. The routes
 * that can produce the decision are declared by one controller. A second command accepting a
 * `CatalogDecision`, a second submission writer, a handler that writes an approval column, or an
 * `/approve` route declared elsewhere all break an assertion.
 *
 * R40 — "only the genuinely missing schema was added". The workflow's footprint on the two catalog
 * tables is exactly the documented columns, plus the two indexes and the two CHECK constraints that
 * make those columns mean something; the categories keep their own `hidden` flag instead of gaining
 * a `published` one. The source scan is deliberately a shape rule, not a column whitelist: any new
 * `approval_*`, `reviewed_*`, `submitted_*` or `published_*` column fails without the list being
 * edited.
 *
 * R39 — "the audit trail reuses the existing infrastructure". `catalog_audit_log` carries the same
 * action/actor/reason/timestamp shape as `identity_audit_log` (data types compared on the live
 * schema, not on the migration text), and nothing under `src/` updates or deletes it, which is what
 * "append-only by convention" means here (ADR-0012).
 *
 * Residual judgement (R2) — the parts no assertion can settle, stated instead of claimed:
 *  - "genuinely missing": the tests prove the footprint, not that a different design would have
 *    needed less schema. Whether approval belonged in a status column on an aggregate rather than on
 *    the denormalized views is a design trade-off, and ADR-0012 records the alternatives that were
 *    rejected.
 *  - "reuses": the tests prove the shape matches. Whether one polymorphic audit table would have been
 *    the better reuse is exactly the alternative ADR-0012 rejects, with its reasons; a test can pin
 *    the outcome, not the judgement behind it.
 *  - "no second mechanism": the tests pin today's single writer and single route owner. Whether a
 *    future command would be "a second mechanism" or a legitimate extension is a review decision.
 */
final class CatalogApprovalDesignTest extends KernelTestCase
{
    private const string AUDIT_TABLE = 'catalog_audit_log';
    private const string IDENTITY_AUDIT_TABLE = 'identity_audit_log';
    private const string PRODUCT_TABLE = 'storefront_product_view';
    private const string CATEGORY_TABLE = 'storefront_category';

    /**
     * Names that belong to the workflow, whatever the design: `hidden` predates it, `published` was
     * added by it, and everything else is matched by prefix — including a `published_at`, which an
     * exact `published` alternative would silently filter out of the assertion below.
     */
    private const string WORKFLOW_COLUMN = '/^(approval_\w*|approved_\w*|submitted_\w*|reviewed_\w*|rejection_\w*|published(_\w*)?|hidden)$/';

    /** @var list<string> */
    private const array PRODUCT_WORKFLOW_COLUMNS = [
        'approval_status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'published',
    ];

    /** @var list<string> */
    private const array CATEGORY_WORKFLOW_COLUMNS = [
        'hidden',
        'approval_status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheDecisionTypeIsNamedOnlyByTheWriteModelAndItsEntryPoints(): void
    {
        self::assertSame(
            [
                'Catalog/Application/Command/DecideCatalogSubmission.php',
                'Catalog/Application/Port/CatalogSubmissionStore.php',
                'Catalog/Domain/Model/CatalogDecision.php',
                'Catalog/Domain/Model/CatalogSubmission.php',
                'Catalog/Infrastructure/Console/BackfillCatalogApprovalCommand.php',
                'Catalog/Infrastructure/Http/AdminCatalogSubmissionController.php',
                'Catalog/Infrastructure/Persistence/DbalCatalogSubmissionStore.php',
            ],
            self::filesMentioning('CatalogDecision'),
            'R4/R10 — a review outcome reaches the database through one write model only: a second command, handler or adapter naming CatalogDecision would be a second approval mechanism.',
        );
    }

    public function testOnlyTheSubmissionStoreWritesTheSubmissionTable(): void
    {
        self::assertSame(
            ['Catalog/Infrastructure/Persistence/DbalCatalogSubmissionStore.php'],
            self::filesWithCodeMatching('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+catalog_submission\b/i'),
            'R4/R10 — the store is the only owner of catalog_submission; a handler or controller writing it would bypass the transition rules, the audit and the outbox.',
        );
    }

    public function testOnlyTheProjectionsWriteApprovalColumnsToTheViewTables(): void
    {
        $writers = [];
        foreach (self::sources() as $path => $contents) {
            $code = self::code($contents);
            if (self::updatesAViewApprovalColumn($code) || self::projectsDynamicApprovalColumns($code)) {
                $writers[] = $path;
            }
        }

        self::assertSame(
            [
                'Catalog/Infrastructure/Projection/ProjectStorefrontApproval.php',
                'Catalog/Infrastructure/Projection/ReplayCatalogSubmission.php',
            ],
            $writers,
            'R10 — the six approval columns of the views are projected from the write model by these two files only; a handler or controller writing them directly would be the second approval mechanism WP3 removed.',
        );
    }

    public function testOneControllerDeclaresEveryDecisionRoute(): void
    {
        $declaring = [];
        foreach (self::sources() as $path => $contents) {
            if (1 === preg_match("#Route\('[^']*/(approve|reject)'#", $contents)) {
                $declaring[] = $path;
            }
        }

        self::assertSame(
            ['Catalog/Infrastructure/Http/AdminCatalogSubmissionController.php'],
            $declaring,
            'R58 — approve and reject are reachable from one controller; a route declared elsewhere is an alternative path to a decision.',
        );

        $routes = self::routeAttributes(
            self::sources()['Catalog/Infrastructure/Http/AdminCatalogSubmissionController.php'],
        );

        self::assertSame(
            ['admin_catalog_approve_submission', 'admin_catalog_reject_submission'],
            self::routeNames($routes),
            'R58 — the controller declares one approve route and one reject route, each exactly once.',
        );

        foreach ($routes as $route) {
            self::assertStringContainsString(
                "methods: ['POST']",
                $route,
                'R58 — a decision is a write: a GET (or an extra verb) on approve/reject would let a link change an approval status.',
            );
        }
    }

    /**
     * @param list<string> $documented
     */
    #[DataProvider('workflowColumnProvider')]
    public function testTheWorkflowColumnsAreExactlyTheDocumentedSet(string $table, array $documented): void
    {
        $workflow = array_values(array_filter(
            array_keys(self::columns($table)),
            static fn (string $column): bool => 1 === preg_match(self::WORKFLOW_COLUMN, $column),
        ));

        self::assertSame(
            $documented,
            $workflow,
            sprintf('R40 — %s must carry the documented workflow columns and nothing else; an undocumented one (an approved_by, a published_at) is schema the feature did not need.', $table),
        );
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function workflowColumnProvider(): iterable
    {
        yield 'products' => [self::PRODUCT_TABLE, self::PRODUCT_WORKFLOW_COLUMNS];
        yield 'categories' => [self::CATEGORY_TABLE, self::CATEGORY_WORKFLOW_COLUMNS];
    }

    public function testTheSubmissionTableCarriesExactlyTheDocumentedColumns(): void
    {
        self::assertSame(
            [
                'kind',
                'slug',
                'approval_status',
                'submitted_by',
                'submitted_at',
                'reviewed_by',
                'reviewed_at',
                'rejection_reason',
                'updated_at',
            ],
            array_keys(self::columns('catalog_submission')),
            'R40 — the write model is the six approval columns plus its key and update moment; a tenth column would be schema the workflow did not need.',
        );
    }

    #[DataProvider('approvalTableProvider')]
    public function testAnApprovalStatusIsMandatoryPendingByDefaultAndRangeConstrained(
        string $table,
        string $constraint,
    ): void {
        $status = self::columns($table)['approval_status'];

        self::assertSame('character varying', $status['type']);
        self::assertSame(20, $status['length'], 'R40 — the column is sized for the three documented values and their longest spelling.');
        self::assertFalse($status['nullable'], 'R40 — a row without an approval status would be neither reviewable nor publicly visible.');
        self::assertStringContainsString(
            'PENDING_APPROVAL',
            $status['default'],
            'R40 — a row inserted by an older code path starts in review, never approved.',
        );

        self::assertContains(
            $constraint,
            self::constraintNames($table),
            'R40 — the CHECK keeps the column to the three documented statuses, so a typo cannot invent a fourth state.',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function approvalTableProvider(): iterable
    {
        yield 'products' => [self::PRODUCT_TABLE, 'chk_storefront_product_approval_status'];
        yield 'categories' => [self::CATEGORY_TABLE, 'chk_storefront_category_approval_status'];
        yield 'submissions' => ['catalog_submission', 'chk_catalog_submission_status'];
    }

    public function testCategoriesKeepHiddenAsTheirPublicationFlagAndGainNoPublishedColumn(): void
    {
        $category = self::columns(self::CATEGORY_TABLE);
        $product = self::columns(self::PRODUCT_TABLE);

        self::assertArrayHasKey('hidden', $category, 'R40 — categories already had a publication flag, and it is the one the storefront reads.');
        self::assertSame('boolean', $category['hidden']['type']);
        self::assertFalse($category['hidden']['nullable'], 'R40 — a nullable hidden flag would make "visible" ambiguous.');

        self::assertArrayNotHasKey('published', $category, 'R40 — a second publication flag on categories would be schema the feature did not need, and two flags disagree eventually.');
        self::assertArrayHasKey('published', $product, 'R40 — products distinguish approval from publication, which is the documented design.');

        self::assertContains('idx_storefront_product_approval', self::indexNames(self::PRODUCT_TABLE), 'R40 — the queue and the storefront both filter on (approval_status, published).');
        self::assertContains('idx_storefront_category_approval', self::indexNames(self::CATEGORY_TABLE));
    }

    public function testTheCatalogTrailReusesTheIdentityTrailShape(): void
    {
        $catalog = self::columns(self::AUDIT_TABLE);
        $identity = self::columns(self::IDENTITY_AUDIT_TABLE);

        self::assertSame(
            ['id', 'action', 'target_type', 'target_slug', 'actor_id', 'reason', 'created_at'],
            array_keys($catalog),
            'R39 — the trail is the documented shape: the identity trail with target_type + target_slug in place of target_user_id, and nothing else.',
        );

        self::assertSame($identity['id']['type'], $catalog['id']['type'], 'R39 — both trails are keyed by UUID.');
        self::assertSame($identity['created_at']['type'], $catalog['created_at']['type'], 'R39 — a timestamp with microseconds and offset, as the identity trail stores it.');
        self::assertFalse($catalog['created_at']['nullable'], 'R39 — an entry without a timestamp cannot order the trail.');

        self::assertSame(
            [$identity['action']['type'], $identity['action']['length']],
            [$catalog['action']['type'], $catalog['action']['length']],
            'R39 — the action vocabulary lives in the same VARCHAR(64) the identity trail uses.',
        );

        self::assertSame(
            [$identity['reason']['type'], $identity['reason']['length']],
            [$catalog['reason']['type'], $catalog['reason']['length']],
            'R39 — the reason is free text in both trails.',
        );
        self::assertTrue($catalog['reason']['nullable'], 'R39 — only a rejection carries a reason, so the column is nullable here (the identity trail always records one).');

        self::assertSame(
            [$identity['actor_admin_id']['type'], $identity['actor_admin_id']['length'], $identity['actor_admin_id']['nullable']],
            [$catalog['actor_id']['type'], $catalog['actor_id']['length'], $catalog['actor_id']['nullable']],
            'R39 — the actor is a VARCHAR(64) id that may legitimately be "system", exactly as on the identity trail.',
        );

        self::assertContains('idx_catalog_audit_target', self::indexNames(self::AUDIT_TABLE), 'R39 — the trail is read per target, so it is indexed like one.');
    }

    public function testNothingUnderSourceUpdatesOrDeletesTheCatalogTrail(): void
    {
        $offenders = [];
        foreach (self::sources() as $path => $contents) {
            if (1 === preg_match('/(?:UPDATE|DELETE\s+FROM)\s+'.self::AUDIT_TABLE.'\b/i', $contents)) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'R39/ADR-0012 — append-only is a convention the code keeps: an UPDATE or DELETE against the trail erases history no other copy holds.',
        );
    }

    /**
     * The attributes of one controller that name a decision route, read as whole attribute bodies so a
     * reformat onto several lines (which is what php-cs-fixer does to a long one) cannot red the test.
     *
     * @return list<string>
     */
    private static function routeAttributes(string $controller): array
    {
        $found = preg_match_all('/#\[Route\((.*?)\)\]/s', $controller, $matches);
        self::assertIsInt($found);

        return array_values(array_filter(
            $matches[1],
            static fn (string $body): bool => 1 === preg_match('/admin_catalog_(?:approve|reject)_submission/', $body),
        ));
    }

    /**
     * @param list<string> $routes
     *
     * @return list<string>
     */
    private static function routeNames(array $routes): array
    {
        $names = [];
        foreach ($routes as $route) {
            $found = preg_match_all("/name: '(admin_catalog_\w+)'/", $route, $matches);
            self::assertIsInt($found);
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function filesMentioning(string $needle): array
    {
        $files = [];
        foreach (self::sources() as $path => $contents) {
            if (str_contains($contents, $needle)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * A write to a view's approval columns, stated literally in the SQL.
     *
     * The tempered gaps stop at the next statement so a SELECT that mentions the columns before or
     * after an unrelated UPDATE cannot be mistaken for the writer. `published` and `hidden` are
     * deliberately absent: publication is a separate decision, written by its own command.
     */
    private static function updatesAViewApprovalColumn(string $code): bool
    {
        $view = '(?:'.self::PRODUCT_TABLE.'|'.self::CATEGORY_TABLE.')';
        $column = '(?:approval_status|submitted_by|submitted_at|reviewed_by|reviewed_at|rejection_reason)';

        // The statement boundary is applied to a slice of the source rather than repeated inside
        // the pattern: two tempered `{0,800}` gaps overflow PCRE's compiled-pattern limit, which
        // made the guard fail to compile and silently match nothing.
        if (preg_match_all('/\bUPDATE\s+'.$view.'\b/i', $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $match) {
                $statement = substr($code, $match[1], 2000);
                if (1 === preg_match('/;|\bUPDATE\b|\bINSERT\b|\bDELETE\b/i', $statement, $boundary, PREG_OFFSET_CAPTURE, strlen($match[0]))) {
                    $statement = substr($statement, 0, $boundary[0][1]);
                }
                if (1 === preg_match('/\bSET\b.*?\b'.$column.'\b\s*=/is', $statement)) {
                    return true;
                }
            }
        }

        $insert = sprintf(
            '/\bINSERT\s+INTO\s+%s\s*\((?:(?!\)).){0,1200}?\b%s\b/is',
            $view,
            $column,
        );

        return 1 === preg_match($insert, $code);
    }

    /**
     * A projection whose SET clause is interpolated at runtime: the template alone has no column
     * names, so the rule is the pair "SET %s" plus an approval-column assignment in the same file.
     */
    private static function projectsDynamicApprovalColumns(string $code): bool
    {
        return 1 === preg_match('/\bSET\s+%s\b/i', $code)
            && 1 === preg_match('/\b(?:approval_status|submitted_by|submitted_at|reviewed_by|reviewed_at|rejection_reason)\s*=/i', $code);
    }

    /**
     * @return list<string>
     */
    private static function filesWithCodeMatching(string $pattern): array
    {
        $files = [];
        foreach (self::sources() as $path => $contents) {
            if (1 === preg_match($pattern, self::code($contents))) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * The PHP source without comments, preserving strings (where the SQL lives). A guard that reads
     * docblocks would red on prose such as "this never touches approval_status".
     */
    private static function code(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    /**
     * Every PHP file of the application, keyed by its path relative to src/ so an assertion names the
     * file a reviewer has to open.
     *
     * @return array<string, string>
     */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2).'/src';
        $sources = [];
        foreach ((new Finder())->files()->in($root)->name('*.php')->sortByName() as $file) {
            $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }

        self::assertNotSame([], $sources, 'the source tree must be readable for an architecture assertion to mean anything.');

        return $sources;
    }

    /**
     * The live columns of one table, in ordinal order, read from the schema rather than from the
     * migration text: a migration edited but not applied would otherwise pass.
     *
     * @return array<string, array{type: string, length: int, nullable: bool, default: string}>
     */
    private static function columns(string $table): array
    {
        $rows = self::connection()->fetchAllAssociative(
            'SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = :table
             ORDER BY ordinal_position',
            ['table' => $table],
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['column_name']] = [
                'type' => (string) $row['data_type'],
                'length' => (int) ($row['character_maximum_length'] ?? 0),
                'nullable' => 'YES' === $row['is_nullable'],
                'default' => (string) ($row['column_default'] ?? ''),
            ];
        }

        self::assertNotSame([], $columns, sprintf('table "%s" must exist in the migrated test database.', $table));

        return $columns;
    }

    /**
     * @return list<string>
     */
    private static function indexNames(string $table): array
    {
        $rows = self::connection()->fetchAllAssociative(
            'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = :table',
            ['table' => $table],
        );

        return array_map(static fn (array $row): string => (string) $row['indexname'], $rows);
    }

    /**
     * @return list<string>
     */
    private static function constraintNames(string $table): array
    {
        $rows = self::connection()->fetchAllAssociative(
            'SELECT constraint_name FROM information_schema.table_constraints
             WHERE table_schema = current_schema() AND table_name = :table',
            ['table' => $table],
        );

        return array_map(static fn (array $row): string => (string) $row['constraint_name'], $rows);
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return $connection;
    }
}
