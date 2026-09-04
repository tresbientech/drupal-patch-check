<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\HookReport;
use TresBienTech\Drupatch\Tests\PlanFactory;

class HookReportTest extends TestCase
{
    use PlanFactory;

    private function plan(): Plan
    {
        return $this->planFrom([
            'counts' => ['conflicts' => 1, 'merged' => 1, 'applies' => 1],
            'no_release' => ['drupal/domain'],
            'patches' => [
                $this->row(['title' => 'Fix a', 'verdict' => 'conflicts']),
                $this->row(['title' => 'Fix b', 'verdict' => 'applies']),
                $this->row(['package' => 'drupal/core', 'version' => '11.4.5', 'title' => 'Menu cache', 'verdict' => 'merged']),
            ],
        ]);
    }

    // Composer applies a package's patches before this hook runs, so a
    // site where every patch still applies has already been told.
    public function testSaysNothingWhenComposerHasAlreadySaidItAll(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 46],
            'patches' => [$this->row(), $this->row(['title' => 'another'])],
        ]);

        self::assertSame([], HookReport::lines($plan));
    }

    /**
     * @param list<array<string, mixed>> $flagged
     * @param array<string, mixed>       $rest
     */
    private function applyingWithCoreReferences(array $flagged, array $rest = []): Plan
    {
        return $this->planFrom([
            'counts' => ['applies' => 1],
            'patches' => [$this->row(['title' => 'Fix the alter hook', 'verdict' => 'applies', 'result' => [
                'core_references' => $rest + ['target' => '11.4.5', 'checked' => \count($flagged), 'flagged' => $flagged],
            ]])],
        ]);
    }

    public function testAnApplyingPatchWithAFlaggedCoreReferenceIsMentioned(): void
    {
        $lines = HookReport::lines($this->applyingWithCoreReferences([
            ['symbol' => '\\Drupal\\workspaces\\WorkspaceListBuilder', 'kind' => 'moved', 'change_record' => 3500000,
                'issue' => '\\Drupal\\workspaces\\WorkspaceListBuilder was removed in 11.4.0; \\Drupal\\workspaces_ui\\WorkspaceListBuilder carries the name now'],
        ]));

        self::assertStringContainsString('1 with core references to check', $lines[0]);
        self::assertStringContainsString('applies', $lines[1]);
        self::assertStringContainsString('Fix the alter hook', $lines[1]);
        self::assertStringContainsString('core moved: \\Drupal\\workspaces\\WorkspaceListBuilder was removed in 11.4.0', $lines[2]);
    }

    public function testSeveralFlaggedReferencesAreCountedOnTheDetailLine(): void
    {
        $flagged = [];
        foreach (\range(1, 3) as $n) {
            $flagged[] = ['symbol' => '\\Drupal\\Core\\Gone'.$n, 'kind' => 'removed', 'issue' => '\\Drupal\\Core\\Gone'.$n.' was removed in 11.0.0'];
        }

        $lines = HookReport::lines($this->applyingWithCoreReferences($flagged));

        self::assertStringContainsString('core removed: \\Drupal\\Core\\Gone1 was removed in 11.0.0 (+2 more)', $lines[2]);
        self::assertStringNotContainsString('Gone2', \implode("\n", $lines));
    }

    public function testAnApplyingPatchWithOnlyDeprecatedReferencesStaysSilent(): void
    {
        $plan = $this->applyingWithCoreReferences([], ['deprecated' => [['fqn' => '\\Drupal\\Core\\OldThing', 'deprecated_in' => '11.2.0', 'removal_in' => '12.0.0']]]);

        self::assertSame([], HookReport::lines($plan));
    }

    public function testTheFirstLineIsWhatIsLeftToDecide(): void
    {
        $first = HookReport::lines($this->plan())[0];

        self::assertStringContainsString('1 conflicts', $first);
        self::assertStringContainsString('1 merged', $first);
        self::assertStringNotContainsString('applies', $first, 'composer already applied those');
    }

    public function testTheHeadlineCountsEachVerdictAndReadsWhole(): void
    {
        $first = HookReport::lines($this->planFrom([
            'counts' => ['merged' => 2, 'unknown' => 1],
            'patches' => [
                $this->row(['verdict' => 'merged', 'title' => 'a']),
                $this->row(['verdict' => 'merged', 'title' => 'b']),
                $this->row(['verdict' => 'unknown', 'title' => 'c']),
            ],
        ]))[0];

        self::assertSame('<info>Drupal Patch Check</info>: 1 unknown, 2 merged after this update', $first);
    }

    public function testTheHintIsPrintedWhole(): void
    {
        self::assertContains(
            '  run `composer drupatch:check` for the detail, or `--target <version>` before a core upgrade',
            HookReport::lines($this->plan())
        );
    }

    public function testTheFooterIsTheLastThingPrinted(): void
    {
        $lines = HookReport::lines($this->plan());

        self::assertStringContainsString('drupatch:reroll', $lines[\count($lines) - 2]);
        self::assertStringContainsString('--update', $lines[\count($lines) - 1]);
    }

    public function testAPlanWithNothingToRunAddsNoFooter(): void
    {
        $plan = $this->planFrom(['counts' => ['unknown' => 1], 'patches' => [
            $this->row(['verdict' => 'unknown']),
        ]]);
        $lines = HookReport::lines($plan);

        self::assertSame(
            '  run `composer drupatch:check` for the detail, or `--target <version>` before a core upgrade',
            $lines[\count($lines) - 1],
            'the hook gains no line when there is nothing to run'
        );
    }

    // A site mid-upgrade can have more rows than anyone reads in a hook.
    public function testALongListIsCutWithAnEllipsis(): void
    {
        $rows = [];
        for ($i = 0; $i < 25; ++$i) {
            $rows[] = $this->row(['verdict' => 'merged', 'title' => 'Fix '.$i]);
        }
        $lines = HookReport::lines($this->planFrom(['counts' => ['merged' => 25], 'patches' => $rows]));

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
        self::assertStringNotContainsString('Fix b', $lines, 'a applies patch is what composer just applied');
    }

    // An unclear row is the one verdict that says nothing on its own: it
    // covers a package that blocks the upgrade, an unreadable patch file
    // and a mirror a day behind drupal.org, and only the reason separates
    // what a person can act on from what they cannot.
    public function testAnUnclearRowCarriesTheReasonUnderIt(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row([
            'verdict' => 'unknown',
            'package' => 'drupal/domain',
            'version' => '',
            'title' => 'Domain content translations permissions',
            'note' => 'the lock does not install drupal/domain, so there is no release to judge this patch against',
        ])]]);

        $lines = HookReport::lines($plan);

        self::assertStringContainsString('unknown', $lines[1]);
        self::assertStringContainsString('the lock does not install drupal/domain', $lines[2]);
    }

    public function testARowWithNothingToExplainStaysOneLine(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row([
            'verdict' => 'merged',
            'package' => 'drupal/token',
            'title' => 'Cache tag on token replacement',
        ])]]);

        $lines = HookReport::lines($plan);

        self::assertStringContainsString('merged', $lines[1]);
        self::assertStringStartsWith('  run `', $lines[2]);
    }

    public function testEveryRowOpensWithTheSameMarkAsTheReport(): void
    {
        $rows = \array_values(\array_filter(
            HookReport::lines($this->plan()),
            static fn (string $line): bool => 1 === \preg_match('/^  (<\\w+>)?[!?·✓*]/u', $line),
        ));

        self::assertCount(2, $rows, 'a applies patch stays in the tally');
        self::assertStringStartsWith('  <error>!</error> conflicts', $rows[0]);
        self::assertStringStartsWith('  <info>✓</info> merged', $rows[1]);
    }

    public function testPointsAtTheCommandThatShowsMore(): void
    {
        self::assertStringContainsString('composer drupatch:check', \implode("\n", HookReport::lines($this->plan())));
    }

    // The hint prints the `--target <version>` placeholder, so a site
    // already on 11.4.5 is not told to upgrade to where it is.
    public function testTheTargetHintNamesNoVersion(): void
    {
        $hint = \implode("\n", \array_filter(
            HookReport::lines($this->plan()),
            static fn (string $line): bool => \str_contains($line, '--target'),
        ));

        self::assertStringContainsString('--target <version>', $hint);
        self::assertStringNotContainsString('11.4.5', $hint);
    }

    public function testPrintsAWarningTheTallyDependsOn(): void
    {
        $plan = $this->planFrom([
            'counts' => ['unknown' => 1],
            'warnings' => ['9 core patch(es) were not judged: 11.4 does not name a core release.'],
            'patches' => [$this->row(['verdict' => 'unknown'])],
        ]);

        $lines = HookReport::lines($plan);

        self::assertSame('  <comment>! 9 core patch(es) were not judged: 11.4 does not name a core release.</comment>', $lines[1], 'the warning goes under the headline, marked and whole');
    }

    // A loosely applied patch is not work, so the hook prints nothing
    // about it.
    public function testTheHookSaysNothingAboutALooselyAppliedPatch(): void
    {
        $lines = HookReport::lines($this->planFrom([
            'counts' => ['applies' => 1],
            'patches' => [$this->row([
                'verdict' => 'applies',
                'result' => ['strict_refused' => 'the patch carries the packaging block as context'],
            ])],
        ]));

        self::assertSame([], $lines);
    }

    public function testABlockedPackageIsNotNamed(): void
    {
        self::assertStringNotContainsString('drupal/domain', \implode("\n", HookReport::lines($this->plan())));
    }

    // Composer refused to move the package during the update the hook is
    // reporting on, so it has already been said.
    public function testABlockedPackageAloneSaysNothing(): void
    {
        self::assertSame([], HookReport::lines($this->planFrom([
            'counts' => ['applies' => 1],
            'no_release' => ['drupal/domain'],
            'patches' => [$this->row()],
        ])));
    }

    // Composer applied every patch during the update it is reporting on,
    // so there is nothing left to say. A constraint that could be widened
    // is not a reason to speak on a run where nothing about the patches
    // changed.
    public function testAWarningAloneSaysNothing(): void
    {
        self::assertSame([], HookReport::lines($this->planFrom([
            'counts' => ['applies' => 1],
            'no_release' => ['drupal/webform'],
            'warnings' => ['drupal/webform 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.'],
            'patches' => [$this->row()],
        ])));
    }

    // With a row to carry, the warning is the caveat on it.
    public function testAWarningPrintsBesideARowThatNeedsADecision(): void
    {
        $lines = HookReport::lines($this->planFrom([
            'counts' => ['merged' => 1],
            'no_release' => ['drupal/webform'],
            'warnings' => ['drupal/webform 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.'],
            'patches' => [$this->row(['verdict' => 'merged'])],
        ]));

        self::assertStringContainsString('1 merged after this update', $lines[0]);
        self::assertStringContainsString('Widen it to ^6.3.', $lines[1]);
    }

    // The hook is about patches too. A blocked package carrying none has
    // nothing here to caveat.
    public function testAWarningAboutAPackageWithNoPatchesIsNeverPrinted(): void
    {
        $lines = HookReport::lines($this->planFrom([
            'counts' => ['merged' => 1],
            'no_release' => ['drupal/select2'],
            'warnings' => ['drupal/select2 2.0.0 supports 11.4.5; the site requires 2.x-dev@dev. Widen it to ^2.0.'],
            'patches' => [$this->row(['verdict' => 'merged'])],
        ]));

        self::assertStringNotContainsString('drupal/select2', \implode("\n", $lines));
    }

    public function testSaysNothingWhenTheSiteDeclaresNoPatches(): void
    {
        self::assertSame([], HookReport::lines($this->planFrom(['patches' => []])));
    }
}
