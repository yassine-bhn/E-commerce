<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use Agents\PhpStan\NoDoctrineInApplicationRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoDoctrineInApplicationRule>
 */
final class NoDoctrineInApplicationRuleTest extends RuleTestCase
{
    private const FIXTURES = __DIR__.'/../../../.agents/phpstan/fixtures/NoDoctrineInApplication';

    protected function getRule(): Rule
    {
        return new NoDoctrineInApplicationRule();
    }

    public function testItReportsAConnectionDependencyInAnApplicationHandler(): void
    {
        $file = self::FIXTURES.'/HandlerWithDbalConnectionParam.php';

        $this->analyse([$file], [
            ['DIP-003: application may not depend on Doctrine\DBAL\Connection.', 7],
            ['DIP-003: application may not depend on Doctrine\DBAL\Connection.', 11],
        ]);

        $errors = $this->gatherAnalyserErrors([$file]);
        self::assertCount(2, $errors);
        foreach ($errors as $error) {
            self::assertSame('agents.dip003.application', $error->getIdentifier());
        }
    }

    public function testItAllowsAnApplicationHandlerDependentOnAPort(): void
    {
        $this->analyse([self::FIXTURES.'/HandlerWithPortParam.php'], []);
    }

    public function testItAllowsInfrastructureClassesToUseConnection(): void
    {
        $this->analyse([self::FIXTURES.'/InfrastructureClassWithConnection.php'], []);
    }
}
