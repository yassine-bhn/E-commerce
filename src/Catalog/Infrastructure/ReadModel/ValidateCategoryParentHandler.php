<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\ReadModel;

use App\Catalog\Application\Port\CategoryHierarchyReader;
use App\Catalog\Application\Query\ValidateCategoryParent;
use App\Catalog\Application\Service\CategoryHierarchy;
use App\Catalog\Domain\Model\CategoryHierarchyCycleException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * solid Task 4.12 — the cycle rule through the query bus, so the tree editor's pre-flight check
 * never needs the hierarchy service in a controller. A cycle throws
 * CategoryHierarchyCycleException (the controller maps 422 {valid:false,...}).
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ValidateCategoryParentHandler
{
    public function __construct(
        private CategoryHierarchy $hierarchy,
        private CategoryHierarchyReader $parents,
    ) {
    }

    public function __invoke(ValidateCategoryParent $query): void
    {
        $this->hierarchy->assertAcyclic($query->categorySlug, $query->parentSlug, $this->parents->parentMap());
    }
}
