<?php

declare(strict_types=1);

namespace App\Tests\Projection\Shared;

use App\Shared\Application\Projection\ProjectionReplayer;
use App\Shared\Infrastructure\Console\ProjectionReplayCommand;
use App\Shared\Infrastructure\Projection\ProjectionRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * R30 / MIG-002 — `projection:replay` is a registered console command with a required name.
 */
final class ProjectionReplayCommandTest extends KernelTestCase
{
    public function testTheCommandIsRegisteredAndRequiresAName(): void
    {
        $application = $this->application();

        $list = new ApplicationTester($application);
        self::assertSame(0, $list->run(['command' => 'list', '--raw' => true]));
        self::assertStringContainsString(
            'projection:replay',
            $list->getDisplay(),
            'R30: bin/console list must expose projection:replay',
        );

        $missing = new ApplicationTester($application);
        self::assertNotSame(0, $missing->run(['command' => 'projection:replay']), 'A missing name is a usage error');
        self::assertStringContainsString('name', $missing->getDisplay());

        $unknown = new ApplicationTester($application);
        self::assertNotSame(0, $unknown->run(['command' => 'projection:replay', 'name' => 'unknown-projection']));
        self::assertStringContainsString('unknown-projection', $unknown->getDisplay());
    }

    public function testAnUnknownNameListsEverySupportedReplay(): void
    {
        $tester = new ApplicationTester($this->application());
        self::assertNotSame(0, $tester->run(['command' => 'projection:replay', 'name' => 'catalog-product-veiw']));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Supported replays:', $display, 'R30: the failure must tell the operator what they can run');
        self::assertStringContainsString('catalog-product-view', $display, 'R31: the catalog product view is a declared replay name');
    }

    public function testDryRunPassesTheFlagAndReportsWhatWouldBeWritten(): void
    {
        $replayer = new class implements ProjectionReplayer {
            public ?bool $receivedDryRun = null;

            public function name(): string
            {
                return 'fake-view';
            }

            public function source(): string
            {
                return 'fake_source';
            }

            public function replay(bool $dryRun): array
            {
                $this->receivedDryRun = $dryRun;

                return ['written' => 3, 'orphans' => 1];
            }
        };

        $tester = new CommandTester(new ProjectionReplayCommand(new ProjectionRegistry([$replayer])));
        $tester->execute(['name' => 'fake-view', '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertTrue($replayer->receivedDryRun, 'R30: --dry-run reaches the replayer');
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString('fake_source', $tester->getDisplay());
        self::assertStringContainsString('3 row(s)', $tester->getDisplay(), 'R31: the command reports rows written');
        self::assertStringContainsString('1 orphan(s)', $tester->getDisplay(), 'R31: the command reports orphans');
        self::assertStringContainsString('Nothing was written', $tester->getDisplay());
    }

    public function testTheTaggedRegistryWiresTheCatalogProductViewReplay(): void
    {
        $tester = new ApplicationTester($this->application());
        self::assertSame(
            0,
            $tester->run(['command' => 'projection:replay', 'name' => 'catalog-product-view', '--dry-run' => true]),
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString('catalog_products', $display, 'R31: the replay declares its state-based source');
        self::assertStringContainsString('Nothing was written', $display);
    }

    private function application(): Application
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        return $application;
    }
}
