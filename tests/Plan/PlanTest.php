<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Plan;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Scope;

/**
 * The boundary between the server's JSON and the plugin's data.
 */
class PlanTest extends TestCase
{
    public function testCarriesTheHunksTheReleaseAlreadyHas(): void
    {
        $row = PatchRow::fromArray([
            'package' => 'drupal/imagemagick',
            'verdict' => 'conflicts',
            'result' => [
                'hunks_failed' => [
                    ['file' => 'src/Plugin/ImageToolkit/ImagemagickToolkit.php', 'line' => 766, 'reason' => 'patch failed'],
                ],
                'hunks_shipped' => [
                    ['file' => 'src/Plugin/ImageToolkit/ImagemagickToolkit.php', 'line' => 369, 'reason' => 'the release already carries this hunk'],
                ],
            ],
        ]);

        $this->assertSame(
            ['src/Plugin/ImageToolkit/ImagemagickToolkit.php:369'],
            $row->hunksShipped,
        );
    }

    public function testARowTheReleaseCarriesNothingOfHasNoShippedHunks(): void
    {
        $row = PatchRow::fromArray(['package' => 'drupal/webform', 'verdict' => 'applies']);

        $this->assertSame([], $row->hunksShipped);
    }

    public function testReadsThePlanTheApiSends(): void
    {
        $plan = Plan::fromArray([
            'target_core' => '11.4.5',
            'core_installed' => '10.6.9',
            'bundle_date' => '2026-08-11T02:00:00Z',
            'counts' => ['current' => 30],
            'rows' => [
                ['package' => 'drupal/webform', 'status' => 'current'],
                ['package' => 'drupal/domain', 'status' => 'no_release'],
            ],
            'plan' => [
                'counts' => ['conflicts' => 1],
                'missing_files' => ['patchs/local.patch'],
                'patches' => [[
                    'package' => 'drupal/webform', 'project' => 'webform', 'installed' => '6.2.9', 'version' => '6.3.2',
                    'title' => 'Fix a', 'source' => 'patches/a.patch', 'verdict' => 'conflicts',
                    'result' => ['tag' => '6.3.2', 'reroll' => ['status' => 'clean', 'patch' => "diff\n", 'verified' => true]],
                ]],
            ],
        ]);

        self::assertSame('11.4.5', $plan->targetCore);
        self::assertSame('10.6.9', $plan->coreInstalled);
        self::assertSame(['drupal/domain'], $plan->noRelease);
        self::assertSame(['patchs/local.patch'], $plan->missingFiles);
        self::assertCount(1, $plan->patches);

        $row = $plan->patches[0];
        self::assertSame('webform', $row->project);
        self::assertSame('6.3.2', $row->version);
        self::assertNotNull($row->reroll);
        self::assertTrue($row->rerollIsClean());
        self::assertTrue($row->reroll['verified']);
    }

    public function testTheTwoTalliesAreReadFromTheirOwnLevel(): void
    {
        $plan = Plan::fromArray([
            'counts' => ['current' => 30, 'no_release' => 1],
            'plan' => ['counts' => ['applies' => 2]],
        ]);

        self::assertSame(['applies' => 2], $plan->counts, 'the verdict tally is the nested one');
        self::assertSame(['current' => 30, 'no_release' => 1], $plan->packageCounts);
    }

    public function testAScanWithoutThePatchHalfIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        // A well-formed scan, but the patch half never ran: rendering it
        // would report every patch as fine.
        Plan::fromArray(['target_core' => '11.4.5', 'counts' => ['current' => 30], 'rows' => []]);
    }

    public function testAFieldTheServerAddsLaterIsIgnored(): void
    {
        $plan = Plan::fromArray([
            'a_field_from_a_later_version' => ['anything'],
            'plan' => [
                'counts' => [],
                'patches' => [['package' => 'drupal/webform', 'verdict' => 'merged', 'confidence' => 0.9]],
                'a_nested_field_from_a_later_version' => 1,
            ],
        ]);

        self::assertCount(1, $plan->patches, 'an unknown field must not stop an installed plugin working');
    }

    public function testABodyThatIsNotAPlanIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        Plan::fromArray(['error' => 'rate limit exceeded']);
    }

    public function testPatchesThatAreNotAListAreRefused(): void
    {
        $this->expectException(RuntimeException::class);

        Plan::fromArray(['plan' => ['counts' => [], 'patches' => 'lots']]);
    }

    public function testARowWithoutAPackageIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        Plan::fromArray(['plan' => ['patches' => [['title' => 'nameless', 'verdict' => 'merged']]]]);
    }

    public function testAProjectIsDerivedFromThePackageWhenTheServerOmitsIt(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'merged']]]]);

        self::assertSame('webform', $plan->patches[0]->project);
    }

    public function testAMissingOptionalFieldBecomesADefaultRatherThanAFailure(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'merged']]]]);

        self::assertSame('', $plan->targetCore);
        self::assertSame('the installed core', $plan->against());
        self::assertSame([], $plan->counts);
        self::assertNull($plan->patches[0]->reroll);
    }

    public function testAVerdictThisPluginDoesNotKnowIsWork(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'quarantined']]]]);

        self::assertTrue($plan->patches[0]->needsAction());
        self::assertTrue($plan->patches[0]->needsMention());
        self::assertCount(1, $plan->needingAction());
    }

    // Both selections are indexed lists: a caller reads [0], so dropping
    // the rows before it must renumber rather than leave a gap.
    public function testTheSelectionsAreRenumberedLists(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [
            ['package' => 'drupal/token', 'verdict' => 'applies'],
            ['package' => 'drupal/webform', 'verdict' => 'conflicts'],
            ['package' => 'drupal/domain', 'verdict' => 'merged'],
        ]]]);

        $action = $plan->needingAction();
        self::assertSame([0], \array_keys($action));
        self::assertSame('drupal/webform', $action[0]->package);

        $mention = $plan->worthMentioning();
        self::assertSame([0, 1], \array_keys($mention));
        self::assertSame('drupal/webform', $mention[0]->package);
        self::assertSame('drupal/domain', $mention[1]->package);
    }

    public function testAPatchThatAppliesIsNeitherWorkNorWorthALine(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'applies']]]]);

        self::assertFalse($plan->patches[0]->needsAction());
        self::assertFalse($plan->patches[0]->needsMention());
    }

    public function testAnApplyingPatchWithAFlaggedCoreReferenceIsWorthALine(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'applies', 'result' => [
            'core_references' => ['target' => '11.4.5', 'checked' => 1, 'flagged' => [['symbol' => '\\Drupal\\Core\\Gone', 'kind' => 'removed', 'issue' => '\\Drupal\\Core\\Gone was removed in 11.0.0']]],
        ]]]]]);

        self::assertFalse($plan->patches[0]->needsAction(), 'the patch applies');
        self::assertTrue($plan->patches[0]->needsMention(), 'what it references is gone, so say so');
        self::assertSame(1, $plan->patches[0]->flaggedCoreReferences());
    }

    public function testAShippedPatchIsNoWorkButStillWorthALine(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'merged']]]]);

        self::assertFalse($plan->patches[0]->needsAction(), 'nothing is broken');
        self::assertTrue($plan->patches[0]->needsMention(), 'the patch can be deleted, so say so');
    }

    public function testARowFallsBackToItsSourceWhenItHasNoTitle(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [[
            'package' => 'drupal/webform', 'verdict' => 'merged', 'source' => 'https://www.drupal.org/files/issues/a.patch',
        ]]]]);

        self::assertSame('https://www.drupal.org/files/issues/a.patch', $plan->patches[0]->label());
    }

    public function testTheReasonPrefersTheNoteOverTheError(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [[
            'package' => 'drupal/domain', 'verdict' => 'unknown', 'note' => 'no release for 11.4.5',
            'result' => ['error' => 'no repository for domain'],
        ]]]]);

        self::assertSame('no release for 11.4.5', $plan->patches[0]->reason());
    }

    // --package narrows the run: the report, the writes, the fix and the
    // exit code are all about what was named.
    public function testNarrowsToTheNamedPackages(): void
    {
        $plan = $this->wholeSite();

        $only = $plan->only(new Scope(['drupal/webform'], []));

        self::assertSame(['drupal/webform'], $only->packages());
        self::assertCount(2, $only->patches);
        self::assertSame(['conflicts' => 1, 'applies' => 1], $only->counts, 'the counts are recomputed from what is left');
        self::assertSame([], $only->packageCounts, 'a scoped run quotes no site-wide package tally');
        self::assertSame([], $only->noRelease, 'a package that was not named does not block a scoped run');
    }

    public function testAPackageIsNamedWithOrWithoutTheDrupalPrefix(): void
    {
        $plan = $this->wholeSite();

        self::assertSame($plan->only(new Scope(['drupal/webform'], []))->packages(), $plan->only(new Scope(['webform'], []))->packages());
        self::assertSame(['drupal/webform'], $plan->only(new Scope(['WebForm'], []))->packages());
        self::assertSame(['drupal/webform'], $plan->only(new Scope(['  webform  '], []))->packages(), 'a name typed with spaces is the same name');
    }

    // A caller reads [0], so dropping the rows before it must renumber.
    public function testWhatNarrowingKeepsIsARenumberedList(): void
    {
        $only = $this->wholeSite()->only(new Scope(['token'], []));

        self::assertSame([0], \array_keys($only->patches));
        self::assertSame('drupal/token', $only->patches[0]->package);
        self::assertSame([0], \array_keys(($only->raw['plan'] ?? [])['patches'] ?? []));
    }

    public function testTheNarrowedCountsAddUpPerVerdict(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'verdict' => 'applies', 'title' => 'a'],
            ['package' => 'drupal/webform', 'verdict' => 'applies', 'title' => 'b'],
            ['package' => 'drupal/token', 'verdict' => 'merged', 'title' => 'c'],
        ]]]);

        self::assertSame(['applies' => 2], $plan->only(new Scope(['webform'], []))->counts);
    }

    public function testTheNarrowedBlockedListIsARenumberedList(): void
    {
        $plan = Plan::fromArray([
            'rows' => [
                ['package' => 'drupal/autotitle', 'status' => 'no_release'],
                ['package' => 'drupal/domain', 'status' => 'no_release'],
            ],
            'plan' => [
                'patches' => [['package' => 'drupal/domain', 'verdict' => 'unknown', 'title' => 'a']],
            ],
        ]);

        self::assertSame([0], \array_keys($plan->only(new Scope(['domain'], []))->noRelease));
    }

    public function testNarrowingKeepsABlockedPackageThatWasNamed(): void
    {
        $only = $this->wholeSite()->only(new Scope(['domain'], []));

        self::assertSame(['drupal/domain'], $only->noRelease);
    }

    public function testNarrowingToNothingLeavesAPlanWithNoPatches(): void
    {
        self::assertFalse($this->wholeSite()->only(new Scope(['drupal/nothing'], []))->hasPatches());
    }

    public function testAnEmptyPackageListLeavesThePlanAlone(): void
    {
        $plan = $this->wholeSite();

        self::assertSame($plan, $plan->only(Scope::whole()));
    }

    // --json owes the scope it was asked for.
    public function testNarrowingRewritesWhatJsonWouldPrint(): void
    {
        $raw = $this->wholeSite()->only(new Scope(['webform'], []))->raw;
        $nested = $raw['plan'] ?? [];

        self::assertSame(['webform'], $raw['scope']);
        self::assertCount(2, $nested['patches'] ?? []);
        self::assertSame(['conflicts' => 1, 'applies' => 1], $nested['counts'] ?? []);
    }

    // --target latest resolves to a version, and the report says which
    // constraint chose it rather than repeating the word.
    public function testSaysWhichConstraintChoseTheTarget(): void
    {
        $plan = Plan::fromArray([
            'target_core' => '11.4.5',
            'target_from' => 'drupal/core-recommended',
            'plan' => ['patches' => []],
        ]);

        self::assertSame('for a move to core 11.4.5 (the newest drupal/core-recommended allows)', $plan->scenario());
        self::assertStringNotContainsString('latest', $plan->scenario());
    }

    public function testANamedTargetSaysNothingAboutAConstraint(): void
    {
        $plan = Plan::fromArray(['target_core' => '11.4.5', 'plan' => ['patches' => []]]);

        self::assertSame('for a move to core 11.4.5', $plan->scenario());
    }

    public function testTheMoveStartsFromTheInstalledCoreWhenItIsKnown(): void
    {
        $plan = Plan::fromArray(['target_core' => '11.4.5', 'core_installed' => '11.3.12', 'plan' => ['patches' => []]]);

        self::assertSame('for a move from core 11.3.12 to 11.4.5', $plan->scenario());
    }

    public function testAResolvedLatestKeepsItsConstraintAfterBothCores(): void
    {
        $plan = Plan::fromArray(['target_core' => '11.4.5', 'core_installed' => '11.3.12', 'target_from' => '^11.3', 'plan' => ['patches' => []]]);

        self::assertSame('for a move from core 11.3.12 to 11.4.5 (the newest ^11.3 allows)', $plan->scenario());
    }

    // A patch is judged against its own package's release. The core
    // version only decides which release that is.
    public function testTheHeadlineNamesTheMoveRatherThanWhatPatchesMeet(): void
    {
        $plan = Plan::fromArray(['target_core' => '11.4.5', 'plan' => ['patches' => []]]);

        self::assertStringNotContainsString('against', $plan->scenario());
    }

    public function testARunAgainstTheInstalledCoreSaysSo(): void
    {
        $plan = Plan::fromArray(['target_is_installed' => true, 'plan' => ['patches' => []]]);

        self::assertSame('against the releases this site installs', $plan->scenario());
    }

    public function testTheNewVerdictsAreUnderstoodUnchanged(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [
            ['package' => 'drupal/a', 'source' => 'a.patch', 'verdict' => 'merged'],
            ['package' => 'drupal/b', 'source' => 'b.patch', 'verdict' => 'applies'],
            ['package' => 'drupal/c', 'source' => 'c.patch', 'verdict' => 'conflicts'],
        ]]]);

        self::assertSame(
            ['merged', 'applies', 'conflicts'],
            \array_map(static fn (PatchRow $row): string => $row->verdict, $plan->patches),
        );
        self::assertTrue($plan->patches[0]->isMerged());
    }

    public function testARowSaysWhatDecidedItsRelease(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'verdict' => 'applies', 'decided_by' => 'composer'],
            ['package' => 'drupal/token', 'verdict' => 'applies'],
        ]]]);

        self::assertSame('composer', $plan->patches[0]->decidedBy);
        self::assertSame('', $plan->patches[1]->decidedBy, 'a row that names no source says nothing');
    }

    public function testEveryOpenRegionIsNamedByItsFileAndIndex(): void
    {
        $row = self::conflictedOn([
            ['file' => 'src/Manager.php', 'regions' => 2],
            ['file' => 'config/services.yml', 'regions' => 1],
        ]);

        self::assertSame([
            ['file' => 'src/Manager.php', 'region' => 0],
            ['file' => 'src/Manager.php', 'region' => 1],
            ['file' => 'config/services.yml', 'region' => 0],
        ], $row->openRegionList());
        self::assertSame(3, $row->openRegions(), 'the count is the size of the list');
    }

    public function testARegionIsNamedEvenWhenItsTextWasNotSent(): void
    {
        $row = self::conflictedOn([['file' => 'src/Manager.php', 'regions' => 7, 'hunks' => [['line' => 4]]]]);

        self::assertCount(7, $row->openRegionList(), 'the service caps the text it sends, not the regions it numbers');
        self::assertSame(6, $row->openRegionList()[6]['region']);
    }

    public function testACleanRerollLeavesNoRegion(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [[
            'package' => 'drupal/webform', 'title' => 'Fix a', 'verdict' => 'conflicts',
            'result' => ['reroll' => ['status' => 'clean', 'patch' => "diff\n"]],
        ]]]]);

        self::assertSame([], $plan->patches[0]->openRegionList());
        self::assertSame(0, $plan->patches[0]->openRegions());
    }

    public function testAMergeTheDecisionsSettledLeavesNoRegion(): void
    {
        // The service keeps the conflicts the merge started from and
        // reports the resolved merge clean.
        $plan = Plan::fromArray(['plan' => ['patches' => [[
            'package' => 'drupal/webform', 'title' => 'Fix a', 'verdict' => 'conflicts',
            'result' => ['reroll' => [
                'status' => 'clean', 'patch' => "diff\n", 'resolutions_applied' => 1,
                'conflicts' => [['file' => 'src/Manager.php', 'regions' => 1]],
            ]],
        ]]]]);

        self::assertSame([], $plan->patches[0]->openRegionList());
        self::assertSame([], $plan->patches[0]->removedFiles());
    }

    /**
     * One row whose re-roll conflicts on the files given.
     *
     * @param list<array<string, mixed>> $conflicts
     */
    private static function conflictedOn(array $conflicts): PatchRow
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [[
            'package' => 'drupal/webform', 'title' => 'Fix a', 'verdict' => 'conflicts',
            'result' => ['reroll' => ['status' => 'conflicts', 'conflicts' => $conflicts]],
        ]]]]);

        return $plan->patches[0];
    }

    private function wholeSite(): Plan
    {
        return Plan::fromArray([
            'target_core' => '11.4.5',
            'counts' => ['current' => 30, 'no_release' => 1],
            'rows' => [['package' => 'drupal/domain', 'status' => 'no_release']],
            'plan' => [
                'counts' => ['conflicts' => 1, 'applies' => 2],
                'patches' => [
                    ['package' => 'drupal/webform', 'verdict' => 'conflicts', 'title' => 'a'],
                    ['package' => 'drupal/webform', 'verdict' => 'applies', 'title' => 'b'],
                    ['package' => 'drupal/token', 'verdict' => 'applies', 'title' => 'c'],
                ],
            ],
        ]);
    }
}
