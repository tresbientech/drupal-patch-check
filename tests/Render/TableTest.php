<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Coverage;
use TresBienTech\Drupatch\Render\Outcomes;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Tests\PlanFactory;
use TresBienTech\Drupatch\Write\WorkingTree;

class TableTest extends TestCase
{
    use PlanFactory;

    /**
     * The table for a run that judged every patch the site declared.
     *
     * @return list<string>
     */
    private static function table(Plan $plan, int $width = 100, ?Outcomes $outcomes = null): array
    {
        return Report::lines($plan, self::covered($plan), $width, $outcomes);
    }

    /**
     * The whole report for such a run.
     *
     * @return list<string>
     */
    private static function whole(Plan $plan, ?Outcomes $outcomes, int $width): array
    {
        return Report::report($plan, self::covered($plan), $outcomes, $width);
    }

    private static function covered(Plan $plan): Coverage
    {
        return new Coverage(\count($plan->patches), [], [], []);
    }

    /**
     * The table for a run that left some of the site's patches alone.
     *
     * @param list<array{package: string, title: string, reason: string}>                 $skipped
     * @param list<array{package: string, title: string, source: string, reason: string}> $unsent
     *
     * @return list<string>
     */
    private static function tableWith(Plan $plan, array $skipped = [], array $unsent = []): array
    {
        return Report::lines($plan, new Coverage(\count($plan->patches), $skipped, $unsent, [
            'drupal/webform' => '6.2.9',
            'acquia/cohesion' => '7.6.1',
        ]));
    }

    // The package already has a heading, so what it skipped belongs
    // under its rows rather than in a block naming it a second time.
    public function testAPackageInTheTableSaysWhatItSkippedInsideItsOwnBlock(): void
    {
        $lines = self::tableWith($this->plan(), [[
            'package' => 'drupal/webform', 'title' => 'From our gitlab',
            'reason' => 'the service does not fetch from that host',
        ]]);
        $at = self::indexOf($lines, 'Fix b');

        self::assertSame(
            '        <comment>1 patch skipped (the service does not fetch from that host)</comment>',
            $lines[$at + 1],
            'the note starts at the mark column, like a warning under the heading',
        );
    }

    // A package the run judged nothing on is said once, before the
    // groups, so a reader sees what was left out before the verdicts.
    public function testAPackageWithNothingJudgedGetsALineOfItsOwnBeforeTheGroups(): void
    {
        $lines = self::tableWith($this->plan(), [[
            'package' => 'acquia/cohesion', 'title' => 'Page builder fix',
            'reason' => 'not a drupal.org project',
        ]]);
        $at = self::indexOf($lines, 'acquia/cohesion');

        self::assertSame(2, $at, 'right under the header');
        self::assertSame(
            '  <comment>acquia/cohesion 7.6.1   1 patch skipped (not a drupal.org project)</comment>',
            $lines[$at],
        );
        self::assertSame('', $lines[$at + 1], 'a blank line keeps it apart from the first package');
        self::assertStringStartsWith('  drupal/webform', $lines[$at + 2]);
    }

    public function testARunLevelWarningStillComesBeforeTheSkippedPackages(): void
    {
        $plan = $this->planFrom([
            'warnings' => ['9 core patch(es) were not judged: 11.4 does not name a core release.'],
            'patches' => [$this->row()],
        ]);
        $lines = self::tableWith($plan, [[
            'package' => 'acquia/cohesion', 'title' => 'Page builder fix',
            'reason' => 'not a drupal.org project',
        ]]);

        self::assertStringContainsString('9 core patch(es)', $lines[2]);
        self::assertSame('', $lines[3]);
        self::assertStringContainsString('acquia/cohesion', $lines[4]);
    }

    // Counting to zero says nothing a reader can act on; the reasons
    // under it are the answer.
    public function testARunThatJudgedNothingSaysSoInsteadOfCounting(): void
    {
        $plan = $this->planFrom(['counts' => [], 'patches' => []]);
        $skipped = [['package' => 'acquia/cohesion', 'title' => 'Fix', 'reason' => 'not a drupal.org project']];

        self::assertSame(
            '<comment>Drupal Patch Check: no patch could be checked; the reasons are below</comment>',
            self::tableWith($plan, $skipped)[0],
        );
    }

    // The run knows it held this text back and says why under the
    // package, so the service reporting it missing adds nothing.
    public function testAPatchTextTheRunHeldBackIsNotAlsoReportedAsLost(): void
    {
        $plan = $this->planFrom(['missing_files' => ['patches/huge.patch'], 'patches' => [$this->row()]]);
        $unsent = [[
            'package' => 'drupal/webform', 'title' => 'Huge',
            'source' => 'patches/huge.patch', 'reason' => 'above the 16 MB cap',
        ]];

        $out = \implode("\n", self::tableWith($plan, [], $unsent));

        self::assertStringContainsString('1 patch text not sent (above the 16 MB cap)', $out);
        self::assertStringNotContainsString('patch text not sent for:', $out);
    }

    // Nothing held this one back, so the text was lost on the way and a
    // reader has a real problem to look at.
    public function testAPatchTextTheServiceNeverGotIsReportedAsLost(): void
    {
        $plan = $this->planFrom(['missing_files' => ['patches/webform.patch'], 'patches' => [$this->row()]]);

        self::assertStringContainsString(
            '  patch text not sent for: patches/webform.patch',
            \implode("\n", self::tableWith($plan)),
        );
    }

    // A patch taken from a merge request is shared work, so the re-roll
    // belongs where the people who share it will get it.
    public function testAConflictingMergeRequestPatchPointsAtTheRequest(): void
    {
        $out = \implode("\n", self::whole($this->fromMergeRequest('conflicts'), self::wrote(), 100));

        self::assertStringContainsString('drupal/webform takes this patch from a merge request', $out);
        self::assertStringContainsString('https://git.drupalcode.org/project/webform/-/merge_requests/22', $out);
    }

    public function testAnApplyingMergeRequestPatchPointsNowhere(): void
    {
        self::assertStringNotContainsString(
            'merge request',
            \implode("\n", self::whole($this->fromMergeRequest('applies'), self::wrote(), 100)),
        );
    }

    // A plain run makes no re-roll, so it has none to send upstream.
    public function testAPlainRunPointsNowhere(): void
    {
        self::assertStringNotContainsString(
            'merge request',
            \implode("\n", self::whole($this->fromMergeRequest('conflicts'), null, 100)),
        );
    }

    public function testAConflictingLocalPatchPointsNowhere(): void
    {
        self::assertSame([], Report::upstream($this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [
            $this->row(['verdict' => 'conflicts', 'source' => 'patches/webform/fix.patch']),
        ]]), self::wrote()));
    }

    private static function wrote(): Outcomes
    {
        return Outcomes::fromWrite(['written' => [], 'refused' => []]);
    }

    private function fromMergeRequest(string $verdict): Plan
    {
        return $this->planFrom(['counts' => [$verdict => 1], 'patches' => [$this->row([
            'verdict' => $verdict,
            'source' => 'https://git.drupalcode.org/project/webform/-/merge_requests/22.patch',
        ])]]);
    }

    /**
     * @param list<string> $lines
     */
    private static function indexOf(array $lines, string $needle): int
    {
        foreach ($lines as $i => $line) {
            if (\str_contains($line, $needle)) {
                return $i;
            }
        }

        self::fail($needle.' is not in the table');
    }

    private function plan(): Plan
    {
        return $this->planFrom([
            'counts' => ['conflicts' => 1, 'applies' => 1, 'unknown' => 1],
            'package_counts' => ['current' => 30, 'no_release' => 1],
            'no_release' => ['drupal/domain'],
            'patches' => [
                $this->row(['installed' => '6.2.9', 'version' => '6.3.2', 'title' => 'Fix a', 'verdict' => 'conflicts']),
                $this->row(['installed' => '6.2.9', 'version' => '6.3.2', 'title' => 'Fix b', 'verdict' => 'applies']),
                $this->row([
                    'package' => 'drupal/domain', 'project' => 'domain', 'installed' => '2.0.1', 'version' => '',
                    'title' => 'Fix c', 'verdict' => 'unknown', 'note' => 'drupal/domain has no release for 11.4.5',
                ]),
            ],
        ]);
    }

    // The command a reader just ran is what introduces its answer; the
    // dataset behind it is not a fact about this site's patches.
    public function testTheReportIsIntroducedByTheCommandThatWroteIt(): void
    {
        self::assertStringStartsWith(
            '<info>Drupal Patch Check</info>: 3 patches ',
            self::table($this->plan())[0],
        );
    }

    public function testNamesTheReleaseEachVerdictIsAbout(): void
    {
        self::assertStringContainsString('drupal/webform 6.2.9 → 6.3.2', \implode("\n", self::table($this->plan())));
    }

    public function testGroupsPatchesUnderTheirPackage(): void
    {
        $lines = self::table($this->plan());
        $webform = \array_search('  drupal/webform 6.2.9 → 6.3.2   1 conflicts, 1 applies', $lines, true);

        self::assertIsInt($webform);
        self::assertStringContainsString('Fix a', $lines[$webform + 1]);
        self::assertStringContainsString('Fix b', $lines[$webform + 2]);
    }

    public function testSaysWhyABlockedPatchHasNoVerdict(): void
    {
        $out = \implode("\n", self::table($this->plan()));

        self::assertStringContainsString('drupal/domain has no release for 11.4.5', $out);
    }

    public function testListsTheCoreSymbolsAPatchReferencesThatTheTargetRemoved(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row([
            'verdict' => 'applies',
            'result' => ['core_references' => ['target' => '11.4.5', 'checked' => 2, 'flagged' => [
                ['symbol' => '\\Drupal\\workspaces\\WorkspaceListBuilder', 'kind' => 'moved', 'file' => 'src/X.php', 'line' => 9, 'reference' => 'new',
                    'issue' => '\\Drupal\\workspaces\\WorkspaceListBuilder was removed in 11.4.0; \\Drupal\\workspaces_ui\\WorkspaceListBuilder carries the name now'],
            ]]],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringContainsString('core moved: \\Drupal\\workspaces\\WorkspaceListBuilder', $out);
    }

    /**
     * @param list<array<string, mixed>> $flagged
     * @param array<string, mixed>       $rest
     */
    private function withCoreReferences(array $flagged, array $rest = []): Plan
    {
        return $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row([
            'verdict' => 'applies',
            'result' => ['core_references' => $rest + ['target' => '11.4.5', 'checked' => \count($flagged), 'flagged' => $flagged]],
        ])]]);
    }

    public function testSaysWhatChangedAndWhereItIsDocumented(): void
    {
        $out = \implode("\n", self::table($this->withCoreReferences([
            ['symbol' => '\\Drupal\\Core\\Gone', 'kind' => 'removed', 'issue' => '\\Drupal\\Core\\Gone was removed in 11.0.0; replacement: the new_thing service', 'change_record' => 3500000],
            ['symbol' => '\\Drupal\\Core\\Deep\\Base::__construct', 'kind' => 'signature', 'issue' => 'passes 3 argument(s); \\Drupal\\Core\\Deep\\Base declares 4 (4 required) since 10.2.0'],
        ])));

        self::assertStringContainsString('core removed: \\Drupal\\Core\\Gone was removed in 11.0.0; replacement: the new_thing service (change record 3500000)', $out);
        self::assertStringContainsString('core signature: passes 3 argument(s); \\Drupal\\Core\\Deep\\Base declares 4 (4 required) since 10.2.0', $out);
    }

    public function testCapsTheCoreLinesAndCountsTheRest(): void
    {
        $flagged = [];
        foreach (\range(1, 5) as $n) {
            $flagged[] = ['symbol' => '\\Drupal\\Core\\Gone'.$n, 'kind' => 'removed', 'issue' => '\\Drupal\\Core\\Gone'.$n.' was removed in 11.0.0'];
        }

        $lines = self::table($this->withCoreReferences($flagged, ['flagged_more' => 4]));
        $core = \array_values(\array_filter($lines, static fn (string $l): bool => \str_contains($l, 'core removed:')));

        self::assertCount(3, $core);
        self::assertStringContainsString('+6 more core references', \implode("\n", $lines));
    }

    public function testCountsTheDeprecatedReferences(): void
    {
        $out = \implode("\n", self::table($this->withCoreReferences([], ['deprecated' => [
            ['fqn' => '\\Drupal\\Core\\OldThing', 'deprecated_in' => '11.2.0', 'removal_in' => '12.0.0'],
            ['fqn' => '\\Drupal\\Core\\OtherThing', 'deprecated_in' => '11.3.0', 'removal_in' => '12.0.0'],
        ]])));

        self::assertStringContainsString('core deprecated: 2 references, still present at 11.4.5', $out);
    }

    public function testPrintsTheNoteWhenTheReferencesWentUnchecked(): void
    {
        $out = \implode("\n", self::table($this->withCoreReferences([], ['note' => 'references not extracted: the patch does not apply to the tag'])));

        self::assertStringContainsString('references not extracted: the patch does not apply to the tag', $out);
        self::assertDoesNotMatchRegularExpression('/^\s+core (removed|moved|signature):/m', $out);
    }

    public function testAConflictsRowDoesNotRepeatThatThePatchDoesNotApply(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => [
                'hunks_failed' => [['file' => 'memcache.services.yml', 'line' => 3, 'reason' => 'patch failed']],
                'core_references' => ['target' => '11.4.5', 'checked' => 0, 'flagged' => [], 'note' => 'references not extracted: the patch does not apply to the tag'],
            ],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringContainsString('memcache.services.yml:3: patch failed', $out);
        self::assertStringNotContainsString('references not extracted', $out);
    }

    public function testARowWithoutCoreReferencesPrintsNoCoreLine(): void
    {
        self::assertDoesNotMatchRegularExpression('/^\s+core (removed|moved|signature):/m', \implode("\n", self::table($this->plan())));
    }

    // A re-roll has to fix every place the patch failed, so the row names
    // them all rather than the first.
    public function testNamesEveryPlaceARerollWillHaveToFix(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => ['hunks_failed' => [
                ['file' => 'tests/src/Functional/SimplesitemapTest.php', 'reason' => 'does not exist in index'],
                ['file' => 'src/Manager.php', 'line' => 88, 'reason' => 'patch failed'],
                ['file' => 'src/Manager.php', 'line' => 204, 'reason' => 'patch failed'],
            ]],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringContainsString('tests/src/Functional/SimplesitemapTest.php: does not exist in index', $out);
        self::assertStringContainsString('src/Manager.php:88: patch failed', $out);
        self::assertStringContainsString('src/Manager.php:204: patch failed', $out);
    }

    public function testSaysNothingAboutFailedHunksOnAVerdictThatStands(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row([
            'verdict' => 'applies',
            'result' => ['hunks_failed' => [['file' => 'src/Manager.php', 'reason' => 'patch does not apply']]],
        ])]]);

        self::assertStringNotContainsString('src/Manager.php', \implode("\n", self::table($plan)));
    }

    public function testRendersARerollWithoutFailedHunksAsBefore(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [
            $this->row(['verdict' => 'conflicts', 'title' => 'Fix a']),
        ]]);
        $lines = self::table($plan);
        $at = \array_search('  drupal/webform 6.2.9   1 conflicts', $lines, true);

        self::assertIsInt($at);
        self::assertStringContainsString('conflicts', $lines[$at + 1]);
        self::assertStringContainsString('Fix a', $lines[$at + 1]);
        self::assertSame('', $lines[$at + 2] ?? '');
    }

    public function testShowsWhyAPatchCouldNotBeJudged(): void
    {
        $plan = $this->planFrom(['counts' => ['unknown' => 1], 'patches' => [$this->row([
            'verdict' => 'unknown',
            'result' => ['error' => 'local patch file not supplied: send its text under patch_files'],
        ])]]);

        self::assertStringContainsString('local patch file not supplied', \implode("\n", self::table($plan)));
    }

    public function testNamesTheLocalPatchesWhoseTextWasNotSent(): void
    {
        $plan = $this->planFrom(['missing_files' => ['patchs/webform.patch']]);

        self::assertStringContainsString('patchs/webform.patch', \implode("\n", self::table($plan)));
    }

    public function testLeadsWithAWarningTheCountsDependOn(): void
    {
        $plan = $this->planFrom([
            'warnings' => ['9 core patch(es) were not judged: 11.4 does not name a core release.'],
            'patches' => [$this->row()],
        ]);

        $lines = self::table($plan);

        self::assertSame('  <comment>! 9 core patch(es) were not judged: 11.4 does not name a core release.</comment>', $lines[2], 'the warning belongs above the rows, marked and whole');
        self::assertSame('', $lines[3], 'a blank line separates the warnings from the packages');
    }

    // The verdict is applies, so the row is not work; the line says
    // the patch needed a looser reading than git apply gives.
    public function testSaysWhenAStrictApplyRefusedAPatchThatStillApplies(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row([
            'verdict' => 'applies',
            'result' => ['strict_refused' => 'the patch carries the packaging block as context'],
        ])]]);

        $lines = self::table($plan);
        $row = self::rowWith($lines, '· applies   Fix the alter hook');

        self::assertSame(
            '                    <fg=cyan>the patch carries the packaging block as context</>',
            $lines[$row + 1],
            'the note belongs under its row, indented, whole and in the detail colour'
        );
    }

    // A row that only broke because of an earlier patch must say so, or
    // the wrong patch gets re-rolled. The earlier patch is cited by its
    // number, the way the rows above are numbered.
    public function testCitesTheEarlierPatchARowWasJudgedWithoutByItsNumber(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Earlier', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Later', 'verdict' => 'conflicts', 'result' => ['judged_without' => ['Earlier']]]),
        ]]);

        $lines = self::table($plan);
        $row = self::rowWith($lines, '<error>!</error> conflicts Later');

        self::assertSame(
            '                    <fg=cyan>judged with only the part of #1 that applied</>',
            $lines[$row + 1]
        );
    }

    // A row behind several failures names them all, so a reader knows the
    // whole of what the tree carried when it was judged.
    public function testCitesEveryEarlierPatchARowWasJudgedWithout(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'First', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Second', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Third', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Later', 'verdict' => 'applies', 'result' => ['judged_without' => ['First', 'Second', 'Third']]]),
        ]]);

        $lines = self::table($plan);
        $row = self::rowWith($lines, '· applies   Later');

        self::assertSame(
            '                    <fg=cyan>judged with only the parts of #1, #2 and #3 that applied</>',
            $lines[$row + 1]
        );
    }

    // A failure and a shipped hunk in one file are told apart by the line
    // they name, which is the same number in both.
    public function testAFailureAndAShippedHunkBothNameTheirLine(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => [
                'hunks_failed' => [['file' => 'm.module', 'line' => 165, 'reason' => 'patch failed']],
                'hunks_shipped' => [['file' => 'm.module', 'line' => 6, 'reason' => 'this hunk is already in the release']],
            ],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringContainsString('m.module:165: patch failed', $out);
        self::assertStringContainsString('already in the release: m.module:6', $out);
    }

    // A hunk the release already carries is why the patch stopped applying
    // there, so the row says it once rather than twice.
    public function testAHunkThatIsBothFailedAndShippedPrintsOneLine(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => [
                'hunks_failed' => [
                    ['file' => 'm.module', 'line' => 6, 'reason' => 'patch failed'],
                    ['file' => 'm.module', 'line' => 165, 'reason' => 'patch failed'],
                ],
                'hunks_shipped' => [['file' => 'm.module', 'line' => 6, 'reason' => 'this hunk is already in the release']],
            ],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringContainsString('m.module:6: already in the release, not needed', $out);
        self::assertStringContainsString('m.module:165: patch failed', $out);
        self::assertStringNotContainsString('m.module:6: patch failed', $out);
        self::assertStringNotContainsString('already in the release: m.module:6', $out);
    }

    // The service caps what it sends, so a row says how much it is not
    // showing rather than reading as the whole answer.
    public function testAShortenedHunkListSaysHowManyItDropped(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => [
                'hunks_failed' => [['file' => 'm.module', 'line' => 6, 'reason' => 'patch failed']],
                'hunks_failed_total' => 4,
                'hunks_shipped' => [['file' => 'm.module', 'line' => 90, 'reason' => 'this hunk is already in the release']],
                'hunks_shipped_total' => 2,
            ],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringContainsString('+3 more failed hunks', $out);
        self::assertStringContainsString('+1 more hunk already in the release', $out);
    }

    // A list the cap did not touch prints no extra line.
    public function testAWholeHunkListSaysNothingExtra(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => ['hunks_failed' => [['file' => 'm.module', 'line' => 6, 'reason' => 'patch failed']]],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringNotContainsString('more failed hunk', $out);
    }

    // A write run is about the files it wrote. The table is the plain
    // run's answer and repeating it buries the part that is new.
    public function testAWriteRunPrintsNoTable(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1, 'applies' => 1], 'patches' => [
            $this->row(['title' => 'Quiet', 'verdict' => 'applies']),
            $this->row(['title' => 'Broken', 'verdict' => 'conflicts']),
        ]]);

        $out = \implode("\n", self::whole($plan, Outcomes::fromWrite(['written' => [], 'refused' => []]), 100));

        self::assertStringNotContainsString('Quiet', $out);
        self::assertStringNotContainsString('Broken', $out);
        self::assertStringNotContainsString('drupal/webform 6.2.9', $out);
        self::assertStringContainsString('patches: ', $out);
    }

    // The tally says what the run moved, so a reader sees progress
    // without running the check again.
    public function testTheTallyShowsWhatTheWriteMoved(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 2], 'patches' => [
            $this->row(['title' => 'Fix a', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Fix b', 'verdict' => 'conflicts']),
        ]]);
        $written = $this->writtenFile('patches/webform/a.patch', 'clean', 'drupal/webform', 'Fix a', true);

        $out = \implode("\n", self::whole($plan, Outcomes::fromWrite(['written' => [$written], 'refused' => []]), 100));

        self::assertStringContainsString('  patches: 1 now applies, 1 conflicts left', $out);
    }

    // A merge nothing applied is not yet a patch that works, so it moves
    // nothing, and neither does a conflict file.
    public function testAnUnverifiedOrConflictedWriteMovesNothing(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 2], 'patches' => [
            $this->row(['title' => 'Fix a', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Fix b', 'verdict' => 'conflicts']),
        ]]);
        $written = [
            $this->writtenFile('patches/webform/a.patch', 'clean', 'drupal/webform', 'Fix a', false),
            $this->writtenFile('patches/webform/b.conflict.patch', 'conflicts', 'drupal/webform', 'Fix b', false, 2),
        ];

        $out = \implode("\n", self::whole($plan, Outcomes::fromWrite(['written' => $written, 'refused' => []]), 100));

        self::assertStringContainsString('  patches: 2 conflicts left', $out);
        self::assertStringNotContainsString('now appl', $out);
    }

    // A plain run does not ask for a re-roll, so it cannot know the
    // release carries the whole patch. It says a re-roll might.
    public function testAConflictsRowPartlyUpstreamSaysARerollMayFindMore(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => [
                'hunks_failed' => [['file' => 'm.module', 'line' => 165, 'reason' => 'patch failed']],
                'hunks_shipped' => [['file' => 'm.module', 'line' => 6, 'reason' => 'this hunk is already in the release']],
            ],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringContainsString('<fg=cyan>run composer drupatch:reroll to see if the release has the rest</>', $out);
    }

    // Nothing of it is upstream, so there is nothing to suggest.
    public function testAConflictsRowWithNothingUpstreamSaysNothingExtra(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => ['hunks_failed' => [['file' => 'm.module', 'line' => 165, 'reason' => 'patch failed']]],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringNotContainsString('run composer drupatch:reroll', $out);
    }

    // A fix run drops the entries the release already carries, so the
    // tally stops asking for what it just did.
    public function testAFixRunDoesNotAskToDropWhatItDropped(): void
    {
        $plan = $this->planFrom(['counts' => ['merged' => 1, 'conflicts' => 1], 'patches' => [
            $this->row(['title' => 'Menu cache', 'verdict' => 'merged']),
            $this->row(['title' => 'Fix b', 'verdict' => 'conflicts']),
        ]]);
        $outcomes = Outcomes::fromWrite(['written' => [], 'refused' => []]);
        $outcomes->recordFix([['action' => 'dropped', 'package' => 'drupal/webform', 'title' => 'Menu cache', 'path' => '']], 'composer.json');

        $out = \implode("\n", self::whole($plan, $outcomes, 100));

        self::assertStringContainsString('  patches: 1 conflicts left', $out);
        self::assertStringNotContainsString('to drop', $out);
    }

    // git gives no line for a refusal about the file itself, so none is
    // printed.
    public function testAFileLevelRefusalPrintsNoLine(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => ['hunks_failed' => [['file' => 'gone.php', 'reason' => 'does not exist in index']]],
        ])]]);

        $out = \implode("\n", self::table($plan));

        self::assertStringContainsString('gone.php: does not exist in index', $out);
    }

    // The label came from the service; one no row carries is printed
    // as it came rather than dropped.
    public function testALabelNoRowCarriesIsCitedAsItCame(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row([
            'verdict' => 'conflicts',
            'result' => ['judged_without' => ['Domain content translations permissions_files']],
        ])]]);

        $lines = self::table($plan);
        $row = self::rowWith($lines, '<error>!</error> conflicts Fix the alter hook');

        self::assertSame(
            '                    <fg=cyan>judged with only the part of "Domain content translations permissions_files" that applied</>',
            $lines[$row + 1]
        );
    }

    public function testRowsAreNumberedInTheOrderComposerAppliesThem(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Zebra']),
            $this->row(['title' => 'Alpha']),
        ]]);

        $lines = self::table($plan);

        self::assertStringStartsWith('     #1 ', $lines[self::indexOf($lines, 'Zebra')]);
        self::assertStringStartsWith('     #2 ', $lines[self::indexOf($lines, 'Alpha')]);
    }

    public function testNumbersRestartUnderEachPackage(): void
    {
        $lines = self::table($this->plan());

        self::assertStringStartsWith('     #1 ', $lines[self::indexOf($lines, 'Fix a')]);
        self::assertStringStartsWith('     #2 ', $lines[self::indexOf($lines, 'Fix b')]);
        self::assertStringStartsWith('     #1 ', $lines[self::indexOf($lines, 'Fix c')]);
    }

    public function testTheNumberColumnIsRightAligned(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; ++$i) {
            $rows[] = $this->row(['title' => 'Patch '.$i]);
        }
        $lines = self::table($this->planFrom(['patches' => $rows]));

        self::assertStringStartsWith('     #9 ', $lines[self::indexOf($lines, 'Patch 9')]);
        self::assertStringStartsWith('    #10 ', $lines[self::indexOf($lines, 'Patch 10')]);
    }

    // composer.json order is the order composer applies them, so it is
    // the only order in which a patch judged without an earlier one can
    // cite a row already printed.
    public function testPackagesKeepTheOrderTheSiteDeclaresThem(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/zzz', 'title' => 'Fix z', 'verdict' => 'merged']),
            $this->row(['package' => 'drupal/aaa', 'title' => 'Fix a', 'verdict' => 'conflicts']),
        ]]);

        self::assertSame(
            ['drupal/zzz', 'drupal/aaa'],
            self::packagesInOrder(self::table($plan)),
        );
    }

    public function testRowsKeepTheOrderTheSiteDeclaresThem(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Zebra', 'verdict' => 'merged']),
            $this->row(['title' => 'Alpha', 'verdict' => 'merged']),
            $this->row(['title' => 'Yak', 'verdict' => 'conflicts']),
        ]]);

        self::assertSame(['Zebra', 'Alpha', 'Yak'], self::titlesInOrder(self::table($plan)));
    }

    // A package interleaved with others in composer.json still prints
    // its patches together, under its first appearance.
    public function testAPackageAppearsOnceAtItsFirstDeclaration(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/aaa', 'title' => 'First']),
            $this->row(['package' => 'drupal/zzz', 'title' => 'Second']),
            $this->row(['package' => 'drupal/aaa', 'title' => 'Third']),
        ]]);

        self::assertSame(['drupal/aaa', 'drupal/zzz'], self::packagesInOrder(self::table($plan)));
        self::assertSame(['First', 'Third', 'Second'], self::titlesInOrder(self::table($plan)));
    }

    // The cited patch is applied before the row citing it, so declaration
    // order always prints it above.
    public function testACitedPatchIsPrintedAboveTheRowCitingIt(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Earlier', 'verdict' => 'conflicts']),
            $this->row(['title' => 'Later', 'verdict' => 'applies', 'result' => ['judged_without' => ['Earlier']]]),
        ]]);

        $titles = self::titlesInOrder(self::table($plan));

        self::assertSame(['Earlier', 'Later'], $titles);
    }

    public function testABlankLineSeparatesEachPackage(): void
    {
        $lines = self::table($this->plan());
        $domain = \array_search('  drupal/domain 2.0.1   1 unknown', $lines, true);

        self::assertIsInt($domain);
        self::assertSame('', $lines[$domain - 1], 'a package is preceded by a blank line');
    }

    public function testOnePackageRendersWithoutADoubledBlankLine(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row()]]);
        $lines = self::table($plan);

        foreach ($lines as $i => $line) {
            if ('' === $line && '' === ($lines[$i + 1] ?? 'x')) {
                self::fail('two blank lines in a row at line '.$i);
            }
        }
        self::assertNotSame([], $lines);
    }

    public function testTheTallyOmitsVerdictsThePackageHasNone(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'merged'])]]);

        self::assertStringContainsString('drupal/webform 6.2.9   1 merged', \implode("\n", self::table($plan)));
    }

    public function testTheTallyIsWorstVerdictFirst(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'A', 'verdict' => 'merged']),
            $this->row(['title' => 'B', 'verdict' => 'unknown']),
            $this->row(['title' => 'C', 'verdict' => 'conflicts']),
        ]]);

        self::assertStringContainsString(
            '1 conflicts, 1 unknown, 1 merged',
            \implode("\n", self::table($plan)),
        );
    }

    public function testRenderingOnePlanTwiceIsIdentical(): void
    {
        $plan = $this->plan();

        self::assertSame(self::table($plan), self::table($plan));
    }

    /**
     * The index of the row whose text starts with `$opening`.
     *
     * @param list<string> $lines
     */
    private static function rowWith(array $lines, string $opening): int
    {
        foreach ($lines as $i => $line) {
            if (1 === \preg_match('/^ {4,}#\d+ (.*)$/u', $line, $m) && \str_starts_with($m[1], $opening)) {
                return $i;
            }
        }
        self::fail('no row opens with '.$opening);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function packagesInOrder(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (1 === \preg_match('/^  (drupal\/\S+) /', $line, $m)) {
                $out[] = $m[1];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function titlesInOrder(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (1 === \preg_match('/^ {4,}#\d+ \S+ +\S+ +(.+?)(?:  +\S+)?$/u', $line, $m)) {
                $out[] = \trim($m[1]);
            }
        }

        return $out;
    }

    public function testEveryRowEndsWithThePatchFilename(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['source' => 'patchs/webform-3401234.patch']),
        ]]);

        self::assertStringEndsWith('webform-3401234.patch', self::table($plan)[3]);
    }

    public function testAUrlPatchShowsItsLastSegment(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['source' => 'https://www.drupal.org/files/issues/2025-01-02/webform-3399999-12.patch']),
        ]]);

        self::assertStringEndsWith('webform-3399999-12.patch', self::table($plan)[3]);
    }

    public function testAQueryStringIsNotPartOfTheFilename(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['source' => 'https://example.test/p/fix.patch?token=abc']),
        ]]);

        self::assertStringEndsWith('fix.patch', self::table($plan)[3]);
    }

    // The label already is the source, so a column repeating it would
    // print the same string twice on one row.
    public function testAPatchWithNoTitleGetsNoFilenameColumn(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => '', 'source' => 'patchs/webform.patch']),
        ]]);
        $row = self::table($plan)[3];

        self::assertSame(1, \substr_count($row, 'patchs/webform.patch'));
    }

    public function testANarrowTerminalStillPrintsOneRowPerPatch(): void
    {
        $lines = self::table($this->plan(), 80);
        $rows = \array_filter($lines, static fn (string $l): bool => 1 === \preg_match('/^ {4,}#\d+ /u', $l));

        self::assertCount(3, $rows);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(80, \mb_strlen(\strip_tags($line)), 'a row overran the terminal: '.$line);
        }
    }

    public function testAWideTerminalKeepsLongTitlesWhole(): void
    {
        $title = 'Allow numeric machine names in webform handler configuration';
        $plan = $this->planFrom(['patches' => [$this->row(['title' => $title])]]);

        self::assertStringContainsString($title, \implode("\n", self::table($plan, 120)));
    }

    public function testANarrowTerminalShortensTheTitle(): void
    {
        $title = 'Allow numeric machine names in webform handler configuration forms';
        $plan = $this->planFrom(['patches' => [$this->row(['title' => $title])]]);

        $out = \implode("\n", self::table($plan, 80));
        self::assertStringNotContainsString($title, $out);
        self::assertStringContainsString('…', $out);
    }

    public function testATitleIsNeverWrapped(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row([
            'title' => \str_repeat('long title ', 40),
        ])]]);
        $lines = self::table($plan, 80);

        self::assertCount(1, \array_filter($lines, static fn (string $l): bool => \str_contains($l, 'long title')));
    }

    public function testTheFilenamesLineUpInAColumn(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['title' => 'Short', 'source' => 'patchs/a.patch']),
            $this->row(['title' => 'A considerably longer patch title', 'source' => 'patchs/b.patch']),
        ]]);
        $rows = \array_values(\array_filter(
            self::table($plan, 100),
            static fn (string $l): bool => 1 === \preg_match('/^ {4,}#\d+ /u', $l),
        ));

        self::assertCount(2, $rows);
        self::assertSame(
            \mb_strpos($rows[0], 'a.patch'),
            \mb_strpos($rows[1], 'b.patch'),
            'the filename column starts at the same offset on every row',
        );
    }

    public function testARowNeverEndsInWhitespace(): void
    {
        foreach (self::table($this->plan(), 100) as $line) {
            self::assertSame(\rtrim($line), $line, 'a line ends in padding: '.$line);
        }
    }

    public function testContinuationLinesSitUnderTheTitle(): void
    {
        $lines = self::table($this->plan(), 100);
        $row = self::rowWith($lines, '<comment>?</comment> unknown');
        $detail = $lines[$row + 1];

        self::assertSame(
            \mb_strpos(\strip_tags($lines[$row]), 'Fix c'),
            \mb_strlen($detail) - \mb_strlen(\ltrim($detail)),
            'the detail line starts where the title does',
        );
    }

    public function testABareRunPrintsEveryRow(): void
    {
        self::assertStringContainsString('Fix b', \implode("\n", self::whole($this->plan(), null, 100)));
    }

    public function testTheFooterIsTheLastThingTheReportPrints(): void
    {
        $lines = self::whole($this->plan(), null, 100);
        $last = $lines[\count($lines) - 1];

        self::assertStringContainsString('drupatch:reroll', $last);
    }

    public function testTheWrittenFilesPrintAboveTheFooter(): void
    {
        $result = ['written' => [$this->writtenFile('patchs/webform.patch')], 'refused' => [['package' => 'drupal/core', 'title' => 'Fix a', 'path' => 'patches/core/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force']]];
        $lines = self::whole($this->plan(), Outcomes::fromWrite($result), 100);

        $wrote = self::indexOfLineContaining($lines, 'patchs/webform.patch');
        $footer = self::indexOfLineContaining($lines, '--force');

        self::assertLessThan($footer, $wrote);
    }

    public function testAReportWithNothingToRunHasNoFooter(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row()]]);
        $lines = self::whole($plan, null, 100);

        self::assertSame([], Report::footer($plan));
        self::assertStringNotContainsString('Next:', \implode("\n", $lines));
    }

    public function testTheReportIsTheRowsThenTheFilesThenWhatWasNotWrittenThenTheFooter(): void
    {
        $result = ['written' => [$this->writtenFile('patchs/webform.patch')], 'refused' => [['package' => 'drupal/core', 'title' => 'Fix a', 'path' => 'patches/core/a.patch', 'reason' => WorkingTree::UNCOMMITTED, 'lifts' => '--force']]];
        $plan = $this->plan();

        self::assertSame(
            \array_merge(
                self::table($plan, 100, Outcomes::fromWrite($result)),
                Report::written(Outcomes::fromWrite($result)),
                Report::refused(Outcomes::fromWrite($result)),
                Report::rewrite(Outcomes::fromWrite($result)),
                Report::footer($plan, Outcomes::fromWrite($result)),
            ),
            self::whole($plan, Outcomes::fromWrite($result), 100),
        );
    }

    /**
     * @param list<string> $lines
     */
    private static function indexOfLineContaining(array $lines, string $needle): int
    {
        foreach ($lines as $i => $line) {
            if (\str_contains($line, $needle)) {
                return $i;
            }
        }
        self::fail('no line contains '.$needle);
    }

    public function testEveryRowOpensWithItsMark(): void
    {
        $lines = self::table($this->plan());
        $rows = \array_values(\array_filter(
            $lines,
            static fn (string $line): bool => 1 === \preg_match('/^ {4,}#\d+ /', $line),
        ));

        self::assertCount(3, $rows);
        foreach ($rows as $line) {
            self::assertMatchesRegularExpression(
                '/^ {4,}#\d+ (<\w+>)?\S(<\/\w+>)? (conflicts|applies|merged|unknown) /u',
                $line,
                'a row must open with its number, then its mark, and still name its verdict',
            );
        }
    }

    public function testAVerdictTheServerAddedLaterStillRenders(): void
    {
        $plan = $this->planFrom(['counts' => ['needs-a-human' => 1], 'patches' => [$this->row([
            'verdict' => 'needs-a-human',
        ])]]);

        self::assertStringContainsString(
            'needs-a-human',
            \implode("\n", self::table($plan)),
        );
    }

    public function testABlockedPackageHeadingNamesAReleaseAndCarriesNoArrow(): void
    {
        $lines = self::table($this->plan());

        self::assertContains('  drupal/domain 2.0.1   1 unknown', $lines);
        self::assertStringNotContainsString('→ no release for the target', \implode("\n", $lines));
    }

    public function testTwoSpellingsOfOneBranchAreNotAMove(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row([
            'package' => 'drupal/select2', 'project' => 'select2', 'installed' => 'dev-2.x', 'version' => '2.x-dev',
        ])]]);

        self::assertContains('  drupal/select2 dev-2.x   1 applies', self::table($plan));
    }

    public function testEitherSpellingOfOneBranchReadsAsOne(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row([
            'package' => 'drupal/select2', 'project' => 'select2', 'installed' => '2.x-dev', 'version' => 'dev-2.x',
        ])]]);

        self::assertContains('  drupal/select2 2.x-dev   1 applies', self::table($plan));
    }

    public function testTwoDifferentBranchesAreStillAMove(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row([
            'package' => 'drupal/select2', 'project' => 'select2', 'installed' => 'dev-1.x', 'version' => '2.x-dev',
        ])]]);

        self::assertContains('  drupal/select2 dev-1.x → 2.x-dev   1 applies', self::table($plan));
    }

    public function testAReleaseUpgradeIsStillAMove(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row([
            'installed' => '6.2.9', 'version' => '6.3.2',
        ])]]);

        self::assertContains('  drupal/webform 6.2.9 → 6.3.2   1 applies', self::table($plan));
    }

    // The line sits under its own heading, so it does not repeat the
    // package name; it starts at the mark column, not the number column.
    public function testAWarningSitsUnderThePackageItNames(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'no_release' => ['drupal/webform'],
            'warnings' => ['drupal/webform 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.'],
            'patches' => [$this->row()],
        ]);

        $lines = self::table($plan);
        $heading = \array_search('  drupal/webform 6.2.9   1 applies', $lines, true);

        self::assertIsInt($heading);
        self::assertSame(
            '        <comment>! 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.</comment>',
            $lines[$heading + 1],
        );
        self::assertStringStartsWith('     #1 ', $lines[$heading + 2], 'the first row follows the warning');
    }

    public function testAWarningSitsBetweenItsHeadingAndItsFirstRow(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 1, 'applies' => 1],
            'no_release' => ['drupal/domain'],
            'warnings' => ['drupal/domain 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.'],
            'patches' => [
                $this->row(['title' => 'Alpha', 'verdict' => 'conflicts']),
                $this->row(['package' => 'drupal/domain', 'project' => 'domain', 'version' => '2.0.1', 'title' => 'Beta']),
            ],
        ]);

        $lines = self::table($plan);
        $warning = \array_search('        <comment>! 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.</comment>', $lines, true);

        self::assertIsInt($warning);
        self::assertStringContainsString('drupal/domain 2.0.1', $lines[$warning - 1]);
        self::assertStringContainsString('Beta', $lines[$warning + 1]);
    }

    // The report is about patches. A warning naming a package that
    // carries none is the upgrade report coming back in by another door.
    public function testAWarningNamingAPackageWithNoPatchesIsNotPrinted(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'no_release' => ['drupal/domain'],
            'warnings' => ['drupal/domain 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.'],
            'patches' => [$this->row()],
        ]);

        self::assertStringNotContainsString('drupal/domain', \implode("\n", self::table($plan)));
    }

    public function testABlockedPackageCarryingPatchesKeepsItsWarning(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'no_release' => ['drupal/webform'],
            'warnings' => ['drupal/webform 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.'],
            'patches' => [$this->row()],
        ]);

        $lines = self::table($plan);
        $heading = \array_search('  drupal/webform 6.2.9   1 applies', $lines, true);

        self::assertIsInt($heading);
        self::assertStringContainsString('Widen it to ^6.3.', $lines[$heading + 1]);
    }

    // A warning about the run rather than about a package still leads:
    // it says the counts below it cannot be trusted.
    public function testAWarningNamingNoPackageStillLeadsTheReport(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'warnings' => ['9 core patch(es) were not judged: 11.4 does not name a core release.'],
            'patches' => [$this->row()],
        ]);

        $lines = self::table($plan);

        self::assertSame('  <comment>! 9 core patch(es) were not judged: 11.4 does not name a core release.</comment>', $lines[2]);
        self::assertSame('', $lines[3]);
    }

    public function testAWarningNamingAPackageIsNeverPrintedTwice(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'warnings' => ['drupal/webform 6.3.2 supports 11.4.5; the site requires ^6.2. Widen it to ^6.3.'],
            'patches' => [$this->row()],
        ]);

        $marked = \array_filter(self::table($plan), static fn (string $line): bool => \str_contains($line, 'Widen it to ^6.3.'));

        self::assertCount(1, $marked);
    }

    public function testEndsOnThePatchTally(): void
    {
        $out = \implode("\n", self::table($this->plan()));

        self::assertStringContainsString('  patches: 1 conflicts, 1 applies, 1 unknown', $out);
    }

    public function testTheFooterCarriesNoPackageTally(): void
    {
        self::assertStringNotContainsString('packages:', \implode("\n", self::table($this->plan())));
    }

    public function testTheFooterDoesNotListTheBlockedPackages(): void
    {
        self::assertNotContains('  no release for 11.4.5: drupal/domain', self::table($this->plan()));
    }

    public function testTheFooterStillNamesPatchTextThatWasNotSent(): void
    {
        $plan = $this->planFrom([
            'counts' => ['applies' => 1],
            'patches' => [$this->row()],
            'missing_files' => ['drupal/webform "Fix a"'],
        ]);

        self::assertStringContainsString('patch text not sent for: drupal/webform "Fix a"', \implode("\n", self::table($plan)));
    }

    public function testSaysWhenThePlanRanAgainstTheInstalledCore(): void
    {
        $plan = $this->planFrom(['target_is_installed' => true, 'patches' => [$this->row()]]);

        self::assertStringContainsString('against the releases this site installs', self::table($plan)[0]);
    }

    public function testAVerifiedRerollIsListedWithItsStatusBelowTheTally(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->rerolledRow(['status' => 'clean', 'verified' => true], ['title' => 'Fix a'])]]);
        $lines = self::whole($plan, Outcomes::fromWrite(['written' => [$this->writtenFile('patches/webform/fix.patch')], 'refused' => []]), 100);

        $wrote = self::indexOfLineContaining($lines, 'patches/webform/fix.patch');
        self::assertSame('  re-rolled:', $lines[$wrote - 1]);
        self::assertSame('    patches/webform/fix.patch  (verified against the release)', $lines[$wrote]);
        self::assertGreaterThan(self::indexOfLineContaining($lines, 'patches: '), $wrote);
    }

    public function testAConflictFileIsListedWithItsOpenRegions(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->rerolledRow(['status' => 'conflicts'], ['title' => 'Fix a'])]]);
        $written = $this->writtenFile('patches/webform/fix.conflict.patch', 'conflicts', 'drupal/webform', 'Fix a', false, 3);
        $out = \implode("\n", self::whole($plan, Outcomes::fromWrite(['written' => [$written], 'refused' => []]), 100));

        self::assertStringContainsString('  re-rolled with conflicts:', $out);
        self::assertStringContainsString('    patches/webform/fix.conflict.patch  (3 regions to decide)', $out);
    }

    public function testOneOpenRegionIsSingular(): void
    {
        $written = $this->writtenFile('patches/webform/fix.conflict.patch', 'conflicts', 'drupal/webform', 'Fix a', false, 1);
        $out = \implode("\n", Report::written(Outcomes::fromWrite(['written' => [$written], 'refused' => []])));

        self::assertStringContainsString('(1 region to decide)', $out);
    }

    public function testNothingPrintsUnderARowForTheWriteItself(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->rerolledRow(['status' => 'clean', 'verified' => true], ['title' => 'Fix a'])]]);
        $lines = self::whole($plan, Outcomes::fromWrite(['written' => [$this->writtenFile('patches/webform/fix.patch')], 'refused' => []]), 100);

        $wrote = self::indexOfLineContaining($lines, 'patches/webform/fix.patch');
        self::assertSame('', $lines[$wrote + 1] ?? '');
    }

    public function testARunThatWroteNothingListsNothing(): void
    {
        self::assertSame([], Report::written(null));
        self::assertSame([], Report::written(Outcomes::fromWrite(['written' => [], 'refused' => []])));
    }

    /**
     * @return list<string>
     */
    private function refusedRun(string $reason, string $lifts): array
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->rerolledRow(['status' => 'clean'], ['title' => 'Fix a'])]]);
        $refusal = ['package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patches/webform/fix.patch', 'reason' => $reason, 'lifts' => $lifts];

        return self::whole($plan, Outcomes::fromWrite(['written' => [], 'refused' => [$refusal]]), 100);
    }

    public function testARefusalIsListedWithItsReasonBelowTheTally(): void
    {
        $lines = $this->refusedRun(WorkingTree::UNCOMMITTED, '--force');

        $header = self::indexOfLineContaining($lines, 'not re-rolled:');
        self::assertGreaterThan(self::indexOfLineContaining($lines, 'patches: '), $header);
        self::assertSame('    it has uncommitted changes', $lines[$header + 1]);
        self::assertSame('      patches/webform/fix.patch  drupal/webform: Fix a', $lines[$header + 2]);
        self::assertStringContainsString('--force   replaces the file this run would not overwrite', \implode("\n", $lines));
    }

    public function testRefusalsSharingAReasonPrintItOnce(): void
    {
        $lines = \implode("\n", Report::refused(Outcomes::fromWrite(['written' => [], 'refused' => [
            ['package' => 'drupal/core', 'title' => 'Fix a', 'path' => 'patches/core/a.patch', 'reason' => WorkingTree::NOT_A_CHECKOUT, 'lifts' => '--force'],
            ['package' => 'drupal/pathauto', 'title' => 'Fix b', 'path' => 'patches/pathauto/b.patch', 'reason' => WorkingTree::NOT_A_CHECKOUT, 'lifts' => '--force'],
        ]])));

        self::assertSame(1, \substr_count($lines, WorkingTree::NOT_A_CHECKOUT));
        self::assertStringContainsString('drupal/core', $lines);
        self::assertStringContainsString('drupal/pathauto', $lines);
    }

    public function testNothingRefusedPrintsNothing(): void
    {
        self::assertSame([], Report::refused(null));
        self::assertSame([], Report::refused(Outcomes::fromWrite(['written' => [], 'refused' => []])));
    }

    /**
     * @param list<array{action: 'dropped'|'repointed', package: string, title: string, path: string}>                                                               $changes
     * @param list<array{path: string, status: string, package: string, title: string, verified: bool, unioned: list<array{file: string, line: int}>, regions: int}> $written
     *
     * @return list<string>
     */
    private function fixRun(Plan $plan, array $changes, string $declaration = 'composer.json', array $written = []): array
    {
        $outcomes = Outcomes::fromWrite(['written' => $written, 'refused' => []]);
        $outcomes->recordFix($changes, $declaration);

        return self::whole($plan, $outcomes, 100);
    }

    public function testADroppedEntryIsListedUnderTheFileItLeft(): void
    {
        $plan = $this->planFrom(['counts' => ['merged' => 1], 'patches' => [$this->row(['title' => 'Menu cache', 'source' => 'https://example.test/a.patch', 'verdict' => 'merged'])]]);
        $lines = $this->fixRun($plan, [['action' => 'dropped', 'package' => 'drupal/webform', 'title' => 'Menu cache', 'path' => '']]);

        $file = self::indexOfLineContaining($lines, 'composer.json:');
        self::assertGreaterThan(self::indexOfLineContaining($lines, 'patches: '), $file);
        self::assertSame('    - drupal/webform: Menu cache (already in the release)', $lines[$file + 1]);
    }

    public function testADroppedEntryNamesTheFileItLeavesBehind(): void
    {
        $plan = $this->planFrom(['counts' => ['merged' => 1], 'patches' => [$this->row(['title' => 'Menu cache', 'source' => 'patches/menu.patch', 'verdict' => 'merged'])]]);
        $out = \implode("\n", $this->fixRun($plan, [['action' => 'dropped', 'package' => 'drupal/webform', 'title' => 'Menu cache', 'path' => 'patches/menu.patch']]));

        self::assertStringContainsString('    - drupal/webform: Menu cache (already in the release; patches/menu.patch is no longer used and was kept)', $out);
    }

    public function testARepointedEntryShowsItsNewPath(): void
    {
        $plan = $this->planFrom(['counts' => ['conflicts' => 1], 'patches' => [$this->rerolledRow(['status' => 'clean', 'verified' => true], ['title' => 'Fix a', 'source' => 'https://example.test/a.patch'])]]);
        $written = [$this->writtenFile('patches/webform/fix-a.patch')];
        $out = \implode("\n", $this->fixRun($plan, [['action' => 'repointed', 'package' => 'drupal/webform', 'title' => 'Fix a', 'path' => 'patches/webform/fix-a.patch']], 'composer.json', $written));

        self::assertStringContainsString('    ~ drupal/webform: Fix a → patches/webform/fix-a.patch', $out);
    }

    public function testThePatchesFileIsNamedWhenItHoldsTheDeclarations(): void
    {
        $plan = $this->planFrom(['counts' => ['merged' => 1], 'patches' => [$this->row(['title' => 'Menu cache', 'source' => 'https://example.test/a.patch', 'verdict' => 'merged'])]]);
        $out = \implode("\n", $this->fixRun($plan, [['action' => 'dropped', 'package' => 'drupal/webform', 'title' => 'Menu cache', 'path' => '']], 'patches.json'));

        self::assertStringContainsString('  patches.json:', $out);
    }

    public function testAFixThatChangedNothingSaysSoUnderTheTally(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row()]]);
        $lines = $this->fixRun($plan, []);

        self::assertSame('  nothing to change in composer.json: no patch to remove, and every re-roll landed where its entry points', $lines[\count($lines) - 1]);
    }

    public function testAFixThatChangedEntriesIsNotOfferedAgain(): void
    {
        $plan = $this->planFrom(['counts' => ['merged' => 1], 'patches' => [$this->row(['title' => 'Menu cache', 'verdict' => 'merged'])]]);
        $out = \implode("\n", $this->fixRun($plan, [['action' => 'dropped', 'package' => 'drupal/webform', 'title' => 'Menu cache', 'path' => '']]));

        self::assertStringNotContainsString('nothing to change', $out);
        self::assertStringNotContainsString('--update', $out);
    }

    public function testTheHeadlineShowsBothCoresWhenTheSiteHasNotMoved(): void
    {
        $plan = $this->planFrom([
            'target_core' => '11.4.5',
            'core_installed' => '11.3.12',
            'target_is_installed' => false,
            'counts' => ['conflicts' => 1],
            'patches' => [$this->row(['verdict' => 'conflicts'])],
        ]);
        $lines = self::whole($plan, null, 100);

        self::assertStringContainsString('1 patch for a move from core 11.3.12 to 11.4.5', $lines[0]);
        self::assertStringNotContainsString('judged against', \implode("\n", $lines));
    }

    public function testNoLineBelowTheTallyRepeatsTheTarget(): void
    {
        $plan = $this->planFrom([
            'target_core' => '11.4.5',
            'core_installed' => '11.3.12',
            'target_is_installed' => false,
            'counts' => ['conflicts' => 1],
            'patches' => [$this->row(['verdict' => 'conflicts'])],
        ]);
        $lines = self::whole($plan, null, 100);
        $tally = self::indexOfLineContaining($lines, 'patches: ');

        self::assertSame('', $lines[$tally + 1]);
        self::assertStringContainsString('Next:', $lines[$tally + 2]);
    }

    public function testAReportThatWroteEverythingSuggestsNoRerollFlag(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 1],
            'patches' => [$this->row(['verdict' => 'conflicts'])],
        ]);
        $wrote = ['written' => [$this->writtenFile('patches/a.patch')], 'refused' => []];

        self::assertStringNotContainsString('writes the re-roll', \implode("\n", self::whole($plan, Outcomes::fromWrite($wrote), 100)));
    }

    public function testAReportOfARunThatDidNotWriteStillSuggestsTheFlag(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 1],
            'patches' => [$this->row(['verdict' => 'conflicts'])],
        ]);

        self::assertStringContainsString('drupatch:reroll', \implode("\n", self::whole($plan, null, 100)));
    }

    public function testTheReportIsByteIdenticalAcrossTwoRuns(): void
    {
        $plan = $this->planFrom([
            'counts' => ['conflicts' => 2],
            'patches' => [$this->row(['verdict' => 'conflicts'])],
        ]);
        $wrote = ['written' => [], 'refused' => [
            ['package' => 'drupal/z', 'title' => 'Fix z', 'path' => 'patches/z.patch', 'reason' => WorkingTree::NOT_A_CHECKOUT, 'lifts' => '--force'],
            ['package' => 'drupal/a', 'title' => 'Fix a', 'path' => 'patches/a.patch', 'reason' => WorkingTree::NOT_A_CHECKOUT, 'lifts' => '--force'],
        ]];

        self::assertSame(self::whole($plan, Outcomes::fromWrite($wrote), 100), self::whole($plan, Outcomes::fromWrite($wrote), 100));
    }

    public function testSaysHowManyRegionsTheMergeDecidedOnItsOwn(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow([
            'status' => 'clean',
            'patch' => "diff\n",
            'unioned' => [
                ['file' => 'src/Form.php', 'line' => 12],
                ['file' => 'src/Form.php', 'line' => 40],
            ],
        ], ['title' => 'Fix a'])]]);

        self::assertStringContainsString(
            'the release and the patch both added lines in 2 regions; the merge kept both additions, check them',
            \implode("\n", self::table($plan))
        );
    }

    public function testSaysNothingWhenTheMergeDecidedNoRegion(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(
            ['status' => 'clean', 'patch' => "diff\n"],
            ['title' => 'Fix a']
        )]]);

        self::assertStringNotContainsString('kept both additions', \implode("\n", self::table($plan)));
    }

    public function testNamesTheRegionsTheMergeDecidedBesideTheFileItWrote(): void
    {
        $written = ['unioned' => [['file' => 'src/Form.php', 'line' => 12], ['file' => 'src/Batch.php', 'line' => 40]]] + $this->writtenFile('patches/webform-fix-a-1234abcd.patch');
        $lines = Report::written(Outcomes::fromWrite(['written' => [$written], 'refused' => []]));

        self::assertSame('  re-rolled:', $lines[1]);
        self::assertStringContainsString('patches/webform-fix-a-1234abcd.patch', $lines[2]);
        self::assertStringContainsString('both added lines in 2 regions', $lines[3]);
        self::assertSame('        src/Form.php:12', $lines[4]);
        self::assertSame('        src/Batch.php:40', $lines[5]);
    }

    public function testSaysWhenTheMergeRanOnADifferentPatchThanDeclared(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow([
            'status' => 'conflicts',
            'patch' => "diff\n",
            'merged_from' => 'https://git.drupalcode.org/project/redirect/-/merge_requests/45.diff',
        ], ['title' => 'Fix a'])]]);

        self::assertStringContainsString(
            're-rolled from merge_requests/45.diff, the merge request\'s own diff; the declared file decided the verdict',
            \implode("\n", self::table($plan))
        );
    }

    public function testSaysNothingWhenTheMergeRanOnTheDeclaredPatch(): void
    {
        $plan = $this->planFrom(['patches' => [$this->rerolledRow(
            ['status' => 'conflicts', 'patch' => "diff\n"],
            ['title' => 'Fix a']
        )]]);

        self::assertStringNotContainsString('merged from', \implode("\n", self::table($plan)));
    }
}
