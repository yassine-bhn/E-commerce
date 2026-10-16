<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** LAY-004 / ADR-0001 — bounded contexts and forbidden Symfony skeleton paths. */
final class ProjectLayoutTest extends TestCase
{
    private const CONTEXTS = [
        'Catalog', 'Cart', 'Ordering', 'Payment', 'Inventory', 'Shipping', 'Identity', 'Promotions',
    ];

    public function testContextsExistWithLayerFolders(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        foreach (self::CONTEXTS as $context) {
            self::assertDirectoryExists($root.'/'.$context.'/Domain/Model');
            self::assertDirectoryExists($root.'/'.$context.'/Application/CommandHandler');
            self::assertDirectoryExists($root.'/'.$context.'/Infrastructure/ReadModel');
        }
        self::assertDirectoryExists($root.'/Shared/Domain');
    }

    public function testSymfonySkeletonEntityFolderIsAbsent(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        self::assertDirectoryDoesNotExist($root.'/Entity', 'ORM aggregates must live in */Domain/Model, not src/Entity');
        self::assertDirectoryDoesNotExist($root.'/Controller', 'HTTP entrypoints belong in */Infrastructure/Http');
        self::assertDirectoryDoesNotExist($root.'/Repository', 'Repository ports belong in */Domain/Repository');
    }
}
