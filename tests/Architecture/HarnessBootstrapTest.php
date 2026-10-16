<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** Ensures PHPUnit Architecture suite and PSR-4 test autoload are wired. */
final class HarnessBootstrapTest extends TestCase
{
    public function testArchitectureSuiteBootstraps(): void
    {
        self::assertTrue(true);
    }
}
