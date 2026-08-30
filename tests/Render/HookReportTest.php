<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Render\HookReport;
use Tresbien\Drupatch\Tests\PlanFactory;

final class HookReportTest extends TestCase
{
    use PlanFactory;

    private function plan(): Plan
    {
        return $this->planFrom([
            'counts' => ['needs-reroll' => 1, 'shipped' => 1, 'still-needed' => 1],
            'no_release' => ['drupal/domain'],
            'patches' => [
                $this->row(['title' => 'Fix a', 'verdict' => 'needs-reroll']),
                $this->row(['title' => 'Fix b', 'verdict' => 'still-needed']),
                $this->row(['package' => 'drupal/core', 'version' => '11.4.5', 'title' => 'Menu cache', 'verdict' => 'shipped']),
            ],
        ]);
    }

    // Composer applies a package's patches before this hook runs, so a
    // site where every patch still applies has already been told.
    public function testSaysNothingWhenComposerHasAlreadySaidItAll(): void
    {
        $plan = $this->planFrom([
            'counts' => ['still-needed' => 46],
            'patches' => [$this->row(), $this->row(['title' => 'another'])],
        ]);

        self::assertSame([], HookReport::lines($plan));
    }

    public function testTheFirstLineIsWhatIsLeftToDecide(): void
    {
        $first = HookReport::lines($this->plan())[0];

        self::assertStringContainsString('1 need a re-roll', $first);
        self::assertStringContainsString('1 can go', $first);
        self::assertStringNotContainsString('still-needed', $first, 'composer already applied those');
    }

    public function testTheHeadlineCountsEachVerdictAndReadsWhole(): void
    {
        $first = HookReport::lines($this->planFrom([
            'counts' => ['shipped' => 2, 'unknown' => 1],
            'patches' => [
                $this->row(['verdict' => 'shipped', 'title' => 'a']),
                $this->row(['verdict' => 'shipped', 'title' => 'b']),
                $this->row(['verdict' => 'unknown', 'title' => 'c']),
            ],
        ]))[0];

        self::assertSame('<info>drupatch</info>: 1 unclear, 2 can go after this update', $first);
    }

    public function testTheLastLineIsTheWholeHint(): void
    {
        $lines = HookReport::lines($this->plan());

        self::assertSame(
            '  run `composer drupal-patch-check` for the detail, or `--target <version>` before a core upgrade',
            $lines[\count($lines) - 1]
        );
    }

    // A site mid-upgrade can have more rows than anyone reads in a hook.
    public function testALongListIsCutWithAnEllipsis(): void
    {
        $rows = [];
        for ($i = 0; $i < 25; ++$i) {
            $rows[] = $this->row(['verdict' => 'shipped', 'title' => 'Fix '.$i]);
        }
        $lines = HookReport::lines($this->planFrom(['counts' => ['shipped' => 25], 'patches' => $rows]));

        self::assertContains('  …', $lines);
        self::assertStringContainsString('Fix 19', \implode("\n", $lines));
        self::assertStringNotContainsString('Fix 20', \implode("\n", $lines), 'the list stops at twenty');
    }

    public function testAVerdictThisPluginDoesNotKnowStillCounts(): void
    {
        $first = HookReport::lines($this->planFrom([
            'counts' => ['quarantined' => 1],
            'patches' => [$this->row(['verdict' => 'quarantined'])],
        ]))[0];

        self::assertStringContainsString('1 quarantined', $first);
    }

    public function testListsOnlyThePatchesNeedingAttention(): void
    {
        $lines = \implode("\n", HookReport::lines($this->plan()));

        self::assertStringContainsString('Fix a', $lines);
        self::assertStringContainsString('Menu cache', $lines);
        self::assertStringNotContainsString('Fix b', $lines, 'a still-needed patch is what composer just applied');
    }

    public function testPointsAtTheCommandThatShowsMore(): void
    {
        self::assertStringContainsString('composer drupal-patch-check', \implode("\n", HookReport::lines($this->plan())));
    }

    // The hint used to name 11.4.5, which on a site running 11.4.5 read as
    // advice to upgrade to where it already is.
    public function testTheTargetHintNamesNoVersion(): void
    {
        $lines = HookReport::lines($this->plan());
        $hint = $lines[\count($lines) - 1];

        self::assertStringContainsString('--target <version>', $hint);
        self::assertStringNotContainsString('11.4.5', $hint);
    }

    public function testPrintsAWarningTheTallyDependsOn(): void
    {
        $plan = $this->planFrom([
            'counts' => ['still-needed' => 1],
            'warnings' => ['9 core patch(es) were not judged: 11.4 does not name a core release.'],
            'patches' => [$this->row()],
        ]);

        $lines = HookReport::lines($plan);

        self::assertSame('  <comment>! 9 core patch(es) were not judged: 11.4 does not name a core release.</comment>', $lines[1], 'the warning goes under the headline, marked and whole');
    }

    // A loosely applied patch is not work, so the hook prints nothing
    // about it.
    public function testTheHookSaysNothingAboutALooselyAppliedPatch(): void
    {
        $lines = HookReport::lines($this->planFrom([
            'counts' => ['still-needed' => 1],
            'patches' => [$this->row([
                'verdict' => 'still-needed',
                'result' => ['strict_refused' => 'the patch carries the packaging block as context'],
            ])],
        ]));

        self::assertSame([], $lines);
    }

    public function testNamesThePackagesThatBlockAnUpgrade(): void
    {
        self::assertStringContainsString('drupal/domain', \implode("\n", HookReport::lines($this->plan())));
    }

    // A blocked package is worth a line even when every patch applies.
    public function testABlockedPackageAloneIsWorthSpeaking(): void
    {
        $lines = HookReport::lines($this->planFrom([
            'counts' => ['still-needed' => 1],
            'no_release' => ['drupal/domain'],
            'patches' => [$this->row()],
        ]));

        self::assertNotSame([], $lines);
        self::assertStringContainsString('no patch needs a decision', $lines[0]);
        self::assertStringContainsString('drupal/domain', $lines[1], 'the packages are named once, under the headline');
        self::assertStringNotContainsString('no release for', $lines[0], 'the headline does not repeat the line below it');
    }

    public function testSaysNothingWhenTheSiteDeclaresNoPatches(): void
    {
        self::assertSame([], HookReport::lines($this->planFrom(['patches' => []])));
    }
}
