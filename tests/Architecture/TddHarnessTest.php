<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TddHarnessTest extends TestCase
{
    private const TASK_ID = 'G-tdd-harness-test';

    private string $hook;

    protected function setUp(): void
    {
        $this->hook = dirname(__DIR__, 2).'/.agents/hooks/tdd-gate.sh';
        self::assertFileExists($this->hook);
    }

    public function testItBlocksWhenTheStateFileIsMissing(): void
    {
        $workspace = $this->workspace();

        $result = $this->runHook($workspace, ['src/Shared/Domain/Money.php']);

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('TDD-001', $result->getOutput());
        self::assertStringContainsString('no state file', $result->getOutput());
    }

    public function testItBlocksWhenTheStateFileHasNoRedRecord(): void
    {
        $workspace = $this->workspace();
        $this->writeState($workspace, "# Task\nType: tdd-cycle\n");

        $result = $this->runHook($workspace, ['src/Shared/Domain/Money.php']);

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('TDD-001', $result->getOutput());
        self::assertStringContainsString('missing RED record', $result->getOutput());
    }

    public function testItBlocksARedPointingOutsideTheTestTree(): void
    {
        $workspace = $this->workspace();
        $this->writeState($workspace, "# Task\nRED: src/Shared/Domain/Money.php — \"Failed asserting that false is true.\" — cmd: vendor/bin/phpunit --filter MoneyTest → FAIL\n");

        $result = $this->runHook($workspace, ['src/Shared/Domain/Money.php']);

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('TDD-002', $result->getOutput());
        self::assertStringContainsString('malformed RED line', $result->getOutput());
    }

    public function testItBlocksARedTestThatIsNotPartOfTheChange(): void
    {
        $workspace = $this->workspace();
        $this->writeState($workspace, "# Task\nRED: tests/Unit/Shared/MoneyTest.php — \"Failed asserting that false is true.\" — cmd: vendor/bin/phpunit --filter MoneyTest → FAIL\n");

        $result = $this->runHook($workspace, ['src/Shared/Domain/Money.php']);

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('TDD-002', $result->getOutput());
        self::assertStringContainsString('must exist and be one of the changed files', $result->getOutput());
    }

    public function testItAcceptsAValidRedBackedByAChangedTest(): void
    {
        $workspace = $this->workspace();
        $this->writeState($workspace, "# Task\nRED: tests/Unit/Shared/MoneyTest.php — \"Failed asserting that false is true.\" — cmd: vendor/bin/phpunit --filter MoneyTest → FAIL\n");
        mkdir($workspace.'/tests/Unit/Shared', 0777, true);
        file_put_contents($workspace.'/tests/Unit/Shared/MoneyTest.php', "<?php\n");
        file_put_contents($workspace.'/src/Shared/Domain/Money.php', "<?php\n");

        $result = $this->runHook($workspace, [
            'src/Shared/Domain/Money.php',
            'tests/Unit/Shared/MoneyTest.php',
        ]);

        self::assertSame(0, $result->getExitCode(), $result->getOutput());
    }

    private function workspace(): string
    {
        $workspace = sys_get_temp_dir().'/tdd-harness-'.uniqid('', true);
        mkdir($workspace.'/.agents/state', 0777, true);
        mkdir($workspace.'/src/Shared/Domain', 0777, true);

        return $workspace;
    }

    private function writeState(string $workspace, string $content): void
    {
        file_put_contents($workspace.'/.agents/state/'.self::TASK_ID.'.md', $content);
    }

    /**
     * @param string[] $args
     */
    private function runHook(string $workspace, array $args): Process
    {
        $process = new Process(array_merge(['bash', $this->hook], $args), $workspace, [
            'TASK_ID' => self::TASK_ID,
            'HOME' => sys_get_temp_dir(),
        ]);
        $process->run();

        return $process;
    }
}
