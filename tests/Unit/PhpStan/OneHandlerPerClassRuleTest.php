<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhpStan;

use Agents\PhpStan\OneHandlerPerClassRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<OneHandlerPerClassRule>
 */
final class OneHandlerPerClassRuleTest extends RuleTestCase
{
    private const FIXTURES = __DIR__.'/../../../.agents/phpstan/fixtures/OneHandlerPerClass';

    protected function getRule(): Rule
    {
        return new OneHandlerPerClassRule();
    }

    public function testItReportsAClassWithTwoHandlerMethods(): void
    {
        $file = self::FIXTURES.'/ClassWithTwoHandlerMethods.php';

        $this->analyse([$file], [
            ['SRP-001: class may declare at most one #[AsMessageHandler] attribute.', 9],
        ]);

        $errors = $this->gatherAnalyserErrors([$file]);
        self::assertCount(1, $errors);
        self::assertSame('agents.srp001.oneHandler', $errors[0]->getIdentifier());
    }

    public function testItAllowsAClassWithOneHandlerMethod(): void
    {
        $this->analyse([self::FIXTURES.'/ClassWithOneHandlerMethod.php'], []);
    }
}
