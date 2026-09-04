<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\CheckCommand;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * What a --fix run prints, and where.
 */
#[CoversClass(CheckCommand::class)]
class FixCommandTest extends TestCase
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
     * A site with one merged patch and one re-rolled patch, judged by a stub server.
     *
     * @param array<string, mixed>             $input
     * @param callable(SiteFixture): void|null $before runs after the site is written, before the command
     */
    private function drive(array $input, ?callable $before = null): CommandTester
    {
        $this->site = (new SiteFixture())
            ->declaresPatch('Menu cache', 'patches/webform/menu.patch')
            ->declaresPatch('Fix', 'patches/webform/fix.patch');
        $this->server = new PlanServer([
            'target_core' => '10.6.9',
            'core_installed' => '10.6.9',
            'target_is_installed' => true,
            'counts' => [],
            'plan' => ['counts' => ['merged' => 1, 'conflicts' => 1], 'patches' => [
                ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'title' => 'Menu cache', 'source' => 'patches/webform/menu.patch', 'verdict' => 'merged'],
                ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'title' => 'Fix', 'source' => 'patches/webform/fix.patch', 'verdict' => 'conflicts',
                    'result' => ['reroll' => ['status' => 'clean', 'verified' => true, 'patch' => "diff --git a/y b/y\n--- a/y\n+++ b/y\n@@ -1 +1 @@\n-a\n+b\n"]]],
            ]],
        ]);
        $composer = $this->site->enter($this->server->endpoint);
        if (null !== $before) {
            $before($this->site);
        }

        $command = new CheckCommand();
        $command->setComposer($composer);
        $command->setApplication(new Application());

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testTheRewriteIsListedBeforeTheFooterAndNotOfferedAgain(): void
    {
        // Not a git checkout: the re-roll is refused and --force is offered,
        // which gives the run a footer to order against.
        $tester = $this->drive(['--fix' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString("  composer.json:\n    - drupal/webform: Menu cache (already in the release; patches/webform/menu.patch is no longer used and was kept)", $display);
        self::assertStringContainsString("  not re-rolled:\n    ".WorkingTree::NOT_A_CHECKOUT."\n      ", $display);
        self::assertLessThan(\strpos($display, 'Next:'), \strpos($display, 'composer.json:'));
        self::assertStringContainsString('--force   replaces the file this run would not overwrite', $display);
        self::assertStringNotContainsString('--fix', $display);
        $declared = \json_decode((string) $this->site?->read('composer.json'), true)['extra']['patches']['drupal/webform'] ?? [];
        self::assertSame(['Fix' => 'patches/webform/fix.patch'], $declared, 'the merged entry is gone, the re-rolled one stays');
    }

    public function testADirtyDeclarationFilePrintsTheReportThenTheError(): void
    {
        $tester = $this->drive(['--fix' => true], static function (SiteFixture $site): void {
            $root = \escapeshellarg($site->root());
            \exec("cd $root && git init -q && git add -A && git -c user.email=t@t -c user.name=t commit -qm init 2>&1", $out, $code);
            self::assertSame(0, $code, \implode("\n", $out));
            $site->write('composer.json', $site->read('composer.json')."\n");
        });

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('patches: ', $display, 'the report still prints');
        self::assertStringContainsString('composer.json has uncommitted changes; commit them or pass --force', $display);
        self::assertGreaterThan(\strpos($display, 'Menu cache'), \strpos($display, 'uncommitted changes'));
    }
}
