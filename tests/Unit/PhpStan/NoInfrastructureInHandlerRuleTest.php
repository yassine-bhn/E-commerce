<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use Agents\PhpStan\NoInfrastructureInHandlerRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoInfrastructureInHandlerRule>
 */
final class NoInfrastructureInHandlerRuleTest extends RuleTestCase
{
    private const FIXTURES = __DIR__.'/../../../.agents/phpstan/fixtures/NoInfrastructureInHandler';

    private const MESSAGE = 'DIP-003: handler constructor must type-hint domain/application ports, not infrastructure or ORM';

    protected function getRule(): Rule
    {
        return new NoInfrastructureInHandlerRule();
    }

    public function testItReportsAConnectionParameter(): void
    {
        $file = self::FIXTURES.'/HandlerWithRawConnectionParam.php';

        $this->analyse([$file], [
            [self::MESSAGE, 11],
        ]);

        $errors = $this->gatherAnalyserErrors([$file]);
        self::assertCount(1, $errors);
        self::assertSame('agents.dip003.noInfrastructure', $errors[0]->getIdentifier());
    }

    public function testItReportsADoctrineDbalTypeParameter(): void
    {
        $file = self::FIXTURES.'/HandlerWithDbalDriverParam.php';

        $this->analyse([$file], [
            [self::MESSAGE, 11],
        ]);

        $errors = $this->gatherAnalyserErrors([$file]);
        self::assertCount(1, $errors);
        self::assertSame('agents.dip003.noInfrastructure', $errors[0]->getIdentifier());
    }

    public function testItReportsAnHttpFoundationParameter(): void
    {
        $file = self::FIXTURES.'/HandlerWithRequestParam.php';

        $this->analyse([$file], [
            [self::MESSAGE, 11],
        ]);

        $errors = $this->gatherAnalyserErrors([$file]);
        self::assertCount(1, $errors);
        self::assertSame('agents.dip003.noInfrastructure', $errors[0]->getIdentifier());
    }

    public function testItAllowsAHandlerDependentOnPortsOnly(): void
    {
        $this->analyse([self::FIXTURES.'/HandlerWithOnlyPortParam.php'], []);
    }

    public function testItAllowsTheSameTypesInInfrastructure(): void
    {
        $this->analyse([self::FIXTURES.'/InfrastructureRepoWithConnection.php'], []);
    }
}
