<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use Agents\PhpStan\ControllerThinRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ControllerThinRule>
 */
final class ControllerThinRuleTest extends RuleTestCase
{
    private const FIXTURES = __DIR__.'/../../../.agents/phpstan/fixtures/ControllerThin';

    protected function getRule(): Rule
    {
        return new ControllerThinRule();
    }

    public function testItReportsConnectionRepositoryAndReadModelDependencies(): void
    {
        $file = self::FIXTURES.'/ForbiddenDepsController.php';

        $this->analyse([$file], [
            ['SRP-002: controller may not depend on Doctrine\DBAL\Connection.', 12],
            ['SRP-002: controller may not depend on App\Cart\Infrastructure\Http\ProductRepository.', 13],
            ['SRP-002: controller may not depend on App\Cart\Infrastructure\Http\OrderViewFinder.', 14],
        ]);

        $errors = $this->gatherAnalyserErrors([$file]);
        self::assertCount(3, $errors);
        foreach ($errors as $error) {
            self::assertSame('agents.srp002.controller', $error->getIdentifier());
        }
    }

    public function testItAllowsTheApprovedDependencyList(): void
    {
        $this->analyse([self::FIXTURES.'/AllowedDepsController.php'], []);
    }
}
