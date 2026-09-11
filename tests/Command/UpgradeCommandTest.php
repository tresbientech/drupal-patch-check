<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\UpgradeCommand;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\Plan;

/**
 * What the upgrade refuses before it asks the service anything. The report
 * itself needs a service answer, so the scratch site drives that.
 */
#[CoversClass(UpgradeCommand::class)]
class UpgradeCommandTest extends TestCase
{
    private ?SiteFixture $site = null;

    private ComposerStub $composer;

    protected function setUp(): void
    {
        $this->composer = new ComposerStub();
    }

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->composer->leave();
        $this->site = null;
    }

    private function drive(string $manager): CommandTester
    {
        $this->site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch')->withManager($manager);
        $composer = $this->site->enter('http://127.0.0.1:1/never-called');

        $command = new UpgradeCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testTheCommandIsNamedForTheMoveAndTakesADryRun(): void
    {
        $command = new UpgradeCommand();

        self::assertSame(Manager::UPGRADE, $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('dry-run'));
        self::assertFalse($command->getDefinition()->getOption('dry-run')->acceptValue());
    }

    public function testASiteAlreadyOnTwoIsToldSo(): void
    {
        $tester = $this->drive('2.0.0');

        self::assertSame(Plan::ACTION_NEEDED, $tester->getStatusCode());
        self::assertStringContainsString(UpgradeCommand::ALREADY, $tester->getDisplay());
        self::assertSame([], $this->composer->commands());
    }

    public function testASiteWithNoManagerHasNothingToMove(): void
    {
        $tester = $this->drive('');

        self::assertStringContainsString(UpgradeCommand::NO_MANAGER, $tester->getDisplay());
    }
}
