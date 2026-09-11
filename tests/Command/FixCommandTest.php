<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TresBienTech\Drupatch\Command\RerollCommand;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * What a re-roll run prints about the declarations it rewrote, and where.
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
    private function drive(array $input, ?callable $before = null, ?SiteFixture $site = null): CommandTester
    {
        $this->site = ($site ?? new SiteFixture())
            ->declaresPatch('Menu cache', 'patches/webform/menu.patch')
            ->declaresPatch('Fix', 'patches/webform/fix.patch');
        $this->server = new PlanServer([
            'target_core' => '10.6.9',
            'core_installed' => '10.6.9',
            'target_is_installed' => true,
            'counts' => [],
            // The service answers no title and an empty source, since the
            // request carries neither; the run puts its own back by position.
            'plan' => ['counts' => ['merged' => 1, 'conflicts' => 1], 'patches' => [
                ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => '', 'verdict' => 'merged'],
                ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => '', 'verdict' => 'conflicts',
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
        // The patch file has uncommitted changes, so the re-roll is refused
        // and --force is offered, which gives the run a footer to order
        // against. composer.json is clean, so its rewrite goes ahead.
        $tester = $this->drive([], null, (new SiteFixture())->inGit()->leavesChanged('patches/webform/fix.patch', "edited\n"));
        $display = $tester->getDisplay();

        self::assertStringContainsString("  composer.json:\n    - drupal/webform: Menu cache (already in the release; patches/webform/menu.patch is no longer used and was kept)", $display);
        self::assertStringContainsString("  not re-rolled:\n    ".WorkingTree::UNCOMMITTED."\n      ", $display);
        self::assertLessThan(\strpos($display, 'Next:'), \strpos($display, 'composer.json:'));
        self::assertStringContainsString('--force   replaces the file this run would not overwrite', $display);
        self::assertStringNotContainsString('--update', $display);
        $declared = \json_decode((string) $this->site?->read('composer.json'), true)['extra']['patches']['drupal/webform'] ?? [];
        self::assertSame(['Fix' => 'patches/webform/fix.patch'], $declared, 'the merged entry is gone, the re-rolled one stays');
    }

    // The declarations move where the site keeps them, not to composer.json.
    public function testTheRewriteLandsInThePatchesFileThatHeldTheEntry(): void
    {
        $tester = $this->drive(['--force' => true], null, (new SiteFixture())
            ->withManager('2.0.0')
            ->inPatchesFile('patches.json'));

        self::assertStringContainsString('  patches.json:', $tester->getDisplay());
        self::assertStringNotContainsString('  composer.json:', $tester->getDisplay());
        $declared = \json_decode((string) $this->site?->read('patches.json'), true)['patches']['drupal/webform'] ?? [];
        self::assertSame(['Fix' => 'patches/webform/fix.patch'], $declared);
        self::assertArrayNotHasKey('patches', (array) (\json_decode((string) $this->site?->read('composer.json'), true)['extra'] ?? []));
    }

    public function testTheRewriteKeepsTheExpandedShapeTheEntryWasWrittenIn(): void
    {
        $this->drive(['--force' => true], null, (new SiteFixture())
            ->withManager('2.0.0')
            ->inPatchesFile('patches.json')
            ->expanded());

        $declared = \json_decode((string) $this->site?->read('patches.json'), true)['patches']['drupal/webform'] ?? [];
        self::assertSame([['description' => 'Fix', 'url' => 'patches/webform/fix.patch']], $declared);
    }
}
