<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\Scope;

/**
 * The command the footer suggests acts on what the run showed.
 */
#[CoversClass(CheckCommand::class)]
class NextCommandTest extends TestCase
{
    private ?SiteFixture $site = null;

    private ?PlanServer $server = null;

    protected function tearDown(): void
    {
        $this->site?->leave();
        $this->server?->stop();
        $this->site = null;
        $this->server = null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function drive(array $input): CommandTester
    {
        $this->site = (new SiteFixture())->declaresPatch('Fix', 'patches/webform/fix.patch');
        $this->server = new PlanServer([
            'target_core' => '10.6.9',
            'core_installed' => '10.6.9',
            'target_is_installed' => true,
            'counts' => [],
            'plan' => ['counts' => ['conflicts' => 1], 'patches' => [[
                'package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9',
                'title' => 'Fix', 'source' => 'patches/webform/fix.patch', 'verdict' => 'conflicts',
            ]]],
        ]);
        $composer = $this->site->enter($this->server->endpoint);

        $command = new CheckCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testANarrowedRunSuggestsANarrowedCommand(): void
    {
        $tester = $this->drive(['--package' => ['webform']]);

        self::assertStringContainsString('composer drupatch:reroll --package webform', $tester->getDisplay());
    }

    public function testABareRunSuggestsABareCommand(): void
    {
        $tester = $this->drive([]);

        self::assertStringContainsString('composer drupatch:reroll', $tester->getDisplay());
        self::assertStringNotContainsString('--package', $tester->getDisplay());
    }

    public function testTheTargetIsRepeatedBeforeThePackages(): void
    {
        self::assertSame(
            ['--target 11.4.5', '--package webform', '--package drupal/token'],
            CheckCommand::repeated('11.4.5', new Scope(['webform', 'drupal/token'], [])),
        );
        self::assertSame([], CheckCommand::repeated('', Scope::whole()));
        self::assertSame(
            ['--package webform', '--patch patches/webform/fix.patch'],
            CheckCommand::repeated('', new Scope(['webform'], ['patches/webform/fix.patch'])),
        );
    }
}
