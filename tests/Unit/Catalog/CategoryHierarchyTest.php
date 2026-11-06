<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\Service\CategoryHierarchy;
use App\Catalog\Domain\Model\CategoryHierarchyCycleException;
use PHPUnit\Framework\TestCase;

/**
 * solid Task 3.2 — the pure hierarchy rule: a category may never become its own ancestor. The
 * parent map comes from the CategoryHierarchyReader port; the traversal is the service's only job.
 */
final class CategoryHierarchyTest extends TestCase
{
    public function testThrowsWhenParentIsSelf(): void
    {
        $hierarchy = new CategoryHierarchy();

        $this->expectException(CategoryHierarchyCycleException::class);
        $this->expectExceptionMessage('Category "piscine" cannot be set as its own parent.');

        $hierarchy->assertAcyclic('piscine', 'piscine', []);
    }

    public function testThrowsWhenParentIsADescendant(): void
    {
        $hierarchy = new CategoryHierarchy();

        // Target parent 'piscine-pompes' has parent chain: piscine-pompes -> piscine-technique -> piscine
        $this->expectException(CategoryHierarchyCycleException::class);
        $this->expectExceptionMessage('Circular category hierarchy detected: "piscine-pompes" is a descendant of "piscine".');

        $hierarchy->assertAcyclic('piscine', 'piscine-pompes', [
            'piscine-pompes' => 'piscine-technique',
            'piscine-technique' => 'piscine',
            'piscine' => null,
        ]);
    }

    public function testANonAncestorParentIsAccepted(): void
    {
        $hierarchy = new CategoryHierarchy();

        $hierarchy->assertAcyclic('piscine', 'plomberie', [
            'plomberie' => null,
            'piscine' => null,
        ]);

        self::expectNotToPerformAssertions(); // reaching this line means the sibling assignment passed
    }

    public function testACorruptParentLoopIsSafeguardedNotSpunOn(): void
    {
        $hierarchy = new CategoryHierarchy();

        // 'a' and 'b' point at each other in the stored data; the traversal must stop, not loop.
        $hierarchy->assertAcyclic('piscine', 'a', [
            'a' => 'b',
            'b' => 'a',
            'piscine' => null,
        ]);

        self::expectNotToPerformAssertions(); // reaching this line means the corrupt chain never cycled
    }

    public function testANullParentIsAcceptedWithoutTraversal(): void
    {
        $hierarchy = new CategoryHierarchy();

        $hierarchy->assertAcyclic('piscine', null, []);

        self::expectNotToPerformAssertions(); // reaching this line means detaching passed
    }
}
