<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * spec-run-12 R18 (with R30) — the public-visibility predicate is written once and composed.
 *
 * R16 moved the reviews guard from the controller into the handlers, which is where QRY-004 wants it.
 * Moving it is only half of the requirement: a handler that re-writes "approved, published and in an
 * approved category" is a second copy of the rule, and the copy is what drifts from the product
 * detail the next time a column changes. Every behavioural test of the reviews path would still pass
 * with such a copy in place — nothing but a source assertion can see it.
 *
 * The single definition already exists: `CatalogVisibility::PRODUCT_PUBLIC` holds the SQL fragment and
 * the public reads interpolate it. This test pins that shape, so a restated predicate in either
 * reviews handler fails here even though the storefront would answer identically today.
 */
final class CatalogVisibilitySingleSourceTest extends TestCase
{
    private const string VISIBILITY = 'Catalog/Infrastructure/ReadModel/CatalogVisibility.php';
    private const string ADAPTER = 'Catalog/Infrastructure/ReadModel/DbalProductVisibility.php';
    private const string REVIEWS_PAGE = 'Catalog/Infrastructure/ReadModel/ListProductReviewsHandler.php';
    private const string REVIEW_WRITE = 'Catalog/Application/CommandHandler/SubmitProductReviewHandler.php';

    /** A restated predicate always names one of the three columns the constant reads. */
    private const string RESTATED = '/approval_status|published\s*(?:=|IS)|hidden\s*(?:=|IS)/i';

    /**
     * Every public read, and the two classes that own the definition. A new read that restates the
     * rule instead of composing it shows up here as a file missing from this list.
     */
    public function testEveryPublicReadComposesTheOnePredicate(): void
    {
        self::assertSame(
            [
                self::VISIBILITY,
                self::ADAPTER,
                self::REVIEWS_PAGE,
                'Catalog/Infrastructure/ReadModel/StorefrontProductRepository.php',
                'Catalog/Infrastructure/Search/StorefrontProductSearchIndexer.php',
            ],
            self::filesMentioning('CatalogVisibility'),
            'R18/R30 — "the storefront serves it" is written once and composed; a public read that does not name CatalogVisibility is a read with its own copy of the rule.',
        );
    }

    public function testTheReviewsPageComposesThePredicateInsteadOfRestatingIt(): void
    {
        $handler = self::source(self::REVIEWS_PAGE);

        self::assertStringContainsString(
            'CatalogVisibility::PRODUCT_PUBLIC',
            $handler,
            'R18 — the reviews page decides visibility with the same fragment the product detail uses, so a hidden product cannot be listed here and not there.',
        );
        self::assertDoesNotMatchRegularExpression(
            self::RESTATED,
            $handler,
            'R18/R30 — the predicate is composed, not restated: naming approval_status, published or hidden in this handler is the divergence the rule forbids.',
        );
    }

    public function testTheReviewWriteAsksThePortThatComposesIt(): void
    {
        // The write is refused before the insert (R17) through the port, because an Application
        // handler cannot name an Infrastructure constant without inverting the dependency.
        $handler = self::source(self::REVIEW_WRITE);

        self::assertStringContainsString(
            'ProductVisibilityPort',
            $handler,
            'R18 — the write asks the port rather than reading the catalogue tables itself.',
        );
        self::assertDoesNotMatchRegularExpression(
            self::RESTATED,
            $handler,
            'R18/R30 — the write composes the shared answer; a visibility fragment in this handler would be a second predicate.',
        );

        $adapter = self::source(self::ADAPTER);
        self::assertStringContainsString(
            'CatalogVisibility::PRODUCT_PUBLIC',
            $adapter,
            'R18 — the port is answered with the shared predicate, not with its own copy of the rule.',
        );
        self::assertDoesNotMatchRegularExpression(
            self::RESTATED,
            $adapter,
            'R30 — the adapter composes the predicate instead of restating it.',
        );
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

    private static function source(string $path): string
    {
        $sources = self::sources();
        self::assertArrayHasKey($path, $sources, sprintf('%s must exist for this assertion to mean anything.', $path));

        return $sources[$path];
    }

    /**
     * Every PHP file of the application, keyed by its path relative to src/ so a failure names the file
     * a reviewer has to open.
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
}
