<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\RerollCommand;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * What an --update run prints, and where.
 */
#[CoversClass(RerollCommand::class)]
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

        $command = new RerollCommand();
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
        $tester = $this->drive(['--update' => true]);
        $display = $tester->getDisplay();

        self::assertStringContainsString("  composer.json:\n    - drupal/webform: Menu cache (already in the release; patches/webform/menu.patch is no longer used and was kept)", $display);
        self::assertStringContainsString("  not re-rolled:\n    ".WorkingTree::NOT_A_CHECKOUT."\n      ", $display);
        self::assertLessThan(\strpos($display, 'Next:'), \strpos($display, 'composer.json:'));
        self::assertStringContainsString('--force   replaces the file this run would not overwrite', $display);
        self::assertStringNotContainsString('--update', $display);
        $declared = \json_decode((string) $this->site?->read('composer.json'), true)['extra']['patches']['drupal/webform'] ?? [];
        self::assertSame(['Fix' => 'patches/webform/fix.patch'], $declared, 'the merged entry is gone, the re-rolled one stays');
    }

    public function testAnEditedPatchDeclarationPrintsTheReportThenTheError(): void
    {
        $tester = $this->drive(['--update' => true], static function (SiteFixture $site): void {
            self::commit($site);
            $decoded = (array) \json_decode((string) $site->read('composer.json'), true);
            $decoded['extra']['patches']['drupal/webform']['Fix'] = 'patches/webform/other.patch';
            $site->write('composer.json', (string) \json_encode($decoded, \JSON_PRETTY_PRINT));
        });

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('patches: ', $display, 'the report still prints');
        self::assertStringContainsString('composer.json has uncommitted changes to its patches; commit them or pass --force', $display);
        self::assertGreaterThan(\strpos($display, 'Menu cache'), \strpos($display, 'uncommitted changes'));
    }

    public function testAnEditElsewhereInTheFileDoesNotStopTheRewrite(): void
    {
        // Reaching a new core means editing constraints, so a run that
        // refused on any change to the file would refuse on every real one.
        $tester = $this->drive(['--update' => true], static function (SiteFixture $site): void {
            self::commit($site);
            $decoded = (array) \json_decode((string) $site->read('composer.json'), true);
            $decoded['description'] = 'edited while reaching the new core';
            $site->write('composer.json', (string) \json_encode($decoded, \JSON_PRETTY_PRINT));
        });

        self::assertStringNotContainsString('uncommitted changes', $tester->getDisplay());
        self::assertStringContainsString('composer.json:', $tester->getDisplay(), 'the rewrite ran');
    }

    public function testARepositoryWithNoCommitYetIsRefused(): void
    {
        // git status reports the file, and `git show HEAD:` has nothing to
        // answer with, so the run cannot tell what the patches were.
        $tester = $this->drive(['--update' => true], static function (SiteFixture $site): void {
            $root = \escapeshellarg($site->root());
            \exec("cd $root && git init -q && git add -A 2>&1", $out, $code);
            self::assertSame(0, $code, \implode("\n", $out));
        });

        self::assertSame(Plan::FAILED, $tester->getStatusCode());
        self::assertStringContainsString('composer.json has uncommitted changes to its patches; commit them or pass --force', $tester->getDisplay());
    }

    private static function commit(SiteFixture $site): void
    {
        $root = \escapeshellarg($site->root());
        \exec("cd $root && git init -q && git add -A && git -c user.email=t@t -c user.name=t commit -qm init 2>&1", $out, $code);
        self::assertSame(0, $code, \implode("\n", $out));
    }
}
