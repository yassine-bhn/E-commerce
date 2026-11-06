<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Query\ValidateCategoryParent;
use App\Catalog\Domain\Model\CategoryHierarchyCycleException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * solid Task 4.12 — the tree editor's pre-flight check through the real query.bus: a cycle
 * throws CategoryHierarchyCycleException (the controller maps 422 {valid:false,...}).
 */
final class ValidateCategoryParentQueryTest extends KernelTestCase
{
    public function testACycleThrows(): void
    {
        $bus = $this->queryBus();

        try {
            $bus->dispatch(new ValidateCategoryParent('piscine', 'piscine'));
            self::fail('a self parent must be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(CategoryHierarchyCycleException::class, $exception->getPrevious());
        }
    }

    public function testANullParentPassesWithoutACycleCheck(): void
    {
        $bus = $this->queryBus();

        $bus->dispatch(new ValidateCategoryParent('piscine', null));

        self::expectNotToPerformAssertions(); // reaching this line means the null parent passed
    }

    private function queryBus(): MessageBusInterface
    {
        self::bootKernel();

        $bus = static::getContainer()->get('messenger.bus.query');
        \assert($bus instanceof MessageBusInterface);

        return $bus;
    }
}
