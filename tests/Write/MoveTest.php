<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Write;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Fetch\PatchText;
use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Tests\PlanFactory;
use TresBienTech\Drupatch\Tests\Scratch;
use TresBienTech\Drupatch\Tests\StubHost;
use TresBienTech\Drupatch\Write\Move;
use TresBienTech\Drupatch\Write\WorkingTree;

/**
 * The patches half of the move: what it copies into the site, and where
 * the re-rolls land once it has.
 */
#[CoversClass(Move::class)]
class MoveTest extends TestCase
{
    use PlanFactory;

    private const MR = 'https://git.drupalcode.org/project/webform/-/merge_requests/940.diff';

    private const REFS = '{"diff_refs":{"base_sha":"aaa","head_sha":"bbb"}}';

    private const DIFF = "diff --git a/a.php b/a.php\n--- a/a.php\n+++ b/a.php\n@@ -1 +1 @@\n-one\n+two\n";

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/drupatch-move-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->root);
    }

    /**
     * A move whose network answers the merge request and its diff, and nothing else.
     */
    private function move(?WorkingTree $tree = null): Move
    {
        $answers = [
            'https://git.drupalcode.org/api/v4/projects/project%2Fwebform/merge_requests/940' => [200, self::REFS],
            'https://git.drupalcode.org/project/webform/-/compare/aaa...bbb?format=diff' => [200, self::DIFF],
        ];
        $fetch = StubHost::fetch($answers);

        // This vendoring gets no tree, so the guard reaches the re-roll alone.
        return new Move($this->root, new Vendoring($this->root, new PatchText($this->root, $fetch, ''), 'patch', Manager::ofVersion('1.7.3')), $tree);
    }

    /**
     * @param list<array{package: string, title: string, source: string, provenance: array<string, string>}> $declarations
     *
     * @return list<string>
     */
    private static function sources(array $declarations): array
    {
        return \array_column($declarations, 'source');
    }

    public function testAUrlPatchIsCopiedInSoItsRerollHasAFileToLandOn(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(['status' => 'clean', 'patch' => "new\n"], ['source' => self::MR])]]);
        $declarations = [['package' => 'drupal/webform', 'title' => 'Fix the alter hook', 'source' => self::MR, 'provenance' => []]];

        $moved = $this->move()->run($plan, $declarations, new Scope([], [self::MR]));

        self::assertSame(['patch/webform/mr940.diff'], self::sources($moved['declarations']));
        self::assertSame("new\n", \file_get_contents($this->root.'/patch/webform/mr940.diff'));
        self::assertSame('bbb', $moved['declarations'][0]['provenance']['head']);
    }

    // The copy and the re-roll are one run, so the guard that protects a
    // patch somebody wrote has no say over the file this run just made.
    public function testTheRerollLandsOnTheCopyThisRunMadeThoughGitCallsItUntracked(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(['status' => 'clean', 'patch' => "new\n"], ['source' => self::MR])]]);
        $declarations = [['package' => 'drupal/webform', 'title' => 'Fix the alter hook', 'source' => self::MR, 'provenance' => []]];

        $moved = $this->move(new WorkingTree(new FakeGit(0, '?? patch/webform/mr940.diff')))
            ->run($plan, $declarations, new Scope([], [self::MR]));

        self::assertSame([], $moved['unwritten']);
        self::assertSame("new\n", \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    // The copy is scoped, so a patch the service left alone keeps its URL
    // and the site's diff to review holds only what had to change.
    public function testAPatchTheServiceDidNotRerollIsLeftAlone(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['source' => self::MR])]]);
        $declarations = [['package' => 'drupal/webform', 'title' => 'Fix the alter hook', 'source' => self::MR, 'provenance' => []]];

        $moved = $this->move()->run($plan, $declarations, Scope::none());

        self::assertSame([self::MR], self::sources($moved['declarations']));
        self::assertSame([], $moved['written']);
        self::assertFalse(\is_file($this->root.'/patch/webform/mr940.diff'));
    }

    public function testTheWholeSiteIsCopiedInWhenTheRunAsksForIt(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['source' => self::MR])]]);
        $declarations = [['package' => 'drupal/webform', 'title' => 'Fix the alter hook', 'source' => self::MR, 'provenance' => []]];

        $moved = $this->move()->run($plan, $declarations, Scope::whole());

        self::assertSame(['patch/webform/mr940.diff'], self::sources($moved['declarations']));
        self::assertSame(self::DIFF, \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }

    // The declaration keeps naming the diff. A conflict file holds markers,
    // and a manager applying one would write them into the code.
    public function testAConflictedRerollIsReportedOpenAndNeverDeclared(): void
    {
        $reroll = ['status' => 'conflicts', 'patch' => '', 'conflicts' => [['file' => 'src/A.php', 'regions' => 1, 'hunks' => [['release' => "one\n", 'patch' => "two\n", 'line' => 1]]]]];
        $plan = $this->planFrom(['patches' => [$this->rerolledRow($reroll, ['source' => 'patch/webform/mr940.diff'])]]);
        \mkdir($this->root.'/patch/webform', 0o777, true);
        \file_put_contents($this->root.'/patch/webform/mr940.diff', self::DIFF);
        $declarations = [['package' => 'drupal/webform', 'title' => 'Fix the alter hook', 'source' => 'patch/webform/mr940.diff', 'provenance' => []]];

        $moved = $this->move()->run($plan, $declarations, Scope::none());

        self::assertSame([['path' => 'patch/webform/mr940.conflict.patch', 'regions' => 1]], $moved['open']);
        self::assertSame(['patch/webform/mr940.diff'], self::sources($moved['declarations']));
        self::assertSame(self::DIFF, \file_get_contents($this->root.'/patch/webform/mr940.diff'));
    }
}
