<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use Agents\PhpStan\InterfaceMethodLimitRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<InterfaceMethodLimitRule>
 */
final class InterfaceMethodLimitRuleTest extends RuleTestCase
{
    private const FIXTURES = __DIR__.'/../../../.agents/phpstan/fixtures/InterfaceMethodLimit';

    protected function getRule(): Rule
    {
        return new InterfaceMethodLimitRule();
    }

    public function testItReportsAnApplicationPortWithFourMethods(): void
    {
        $file = self::FIXTURES.'/FatApplicationPort.php';

        $this->analyse([$file], [
            ['ISP-001: port interface may not have more than 3 methods.', 7],
        ]);

        $errors = $this->gatherAnalyserErrors([$file]);
        self::assertCount(1, $errors);
        self::assertSame('agents.isp001.methodLimit', $errors[0]->getIdentifier());
    }

    public function testItAllowsAnApplicationPortWithThreeMethods(): void
    {
        $this->analyse([self::FIXTURES.'/LeanApplicationPort.php'], []);
    }

    public function testItAllowsAnInfrastructureInterfaceWithFourMethods(): void
    {
        $this->analyse([self::FIXTURES.'/InfrastructureInterfaceWithFourMethods.php'], []);
    }
}
