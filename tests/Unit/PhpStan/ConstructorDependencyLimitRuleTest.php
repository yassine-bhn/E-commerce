<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use Agents\PhpStan\ConstructorDependencyLimitRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ConstructorDependencyLimitRule>
 */
final class ConstructorDependencyLimitRuleTest extends RuleTestCase
{
    private const FIXTURES = __DIR__.'/../../../.agents/phpstan/fixtures/ConstructorDependencyLimit';

    protected function getRule(): Rule
    {
        return new ConstructorDependencyLimitRule();
    }

    public function testItReportsAHandlerWithSixDependencies(): void
    {
        $file = self::FIXTURES.'/SixDependenciesHandler.php';

        $this->analyse([$file], [
            ['ISP-003: service constructor has more than 5 dependencies.', 33],
        ]);

        $errors = $this->gatherAnalyserErrors([$file]);
        self::assertCount(1, $errors);
        self::assertSame('agents.isp003.dependencyLimit', $errors[0]->getIdentifier());
    }

    public function testItAllowsAHandlerWithFiveDependencies(): void
    {
        $this->analyse([self::FIXTURES.'/FiveDependenciesHandler.php'], []);
    }

    public function testItAllowsANonServiceClassWithSixDependencies(): void
    {
        $this->analyse([self::FIXTURES.'/NonServiceWithSixDependencies.php'], []);
    }
}
