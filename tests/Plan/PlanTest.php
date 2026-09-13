<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Plan;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TresBienTech\Drupatch\Manager;
use TresBienTech\Drupatch\Plan\PatchRow;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Plan\Reroll;
use TresBienTech\Drupatch\Read\Declaration;
use TresBienTech\Drupatch\Read\Scope;
use TresBienTech\Drupatch\Render\Report;
use TypeError;

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
        ], null);

        $this->assertSame(
            [['file' => 'src/Plugin/ImageToolkit/ImagemagickToolkit.php', 'line' => 369, 'reason' => 'the release already carries this hunk']],
            $row->hunksShipped,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedElements(): iterable
    {
        yield 'a failed hunk' => [['hunks_failed' => ['webform.module:165']]];
        yield 'a shipped hunk' => [['hunks_shipped' => [6]]];
        yield 'a conflict' => [['reroll' => ['status' => 'conflicts', 'conflicts' => ['src/Entity/Webform.php']]]];
        yield 'a conflict hunk' => [['reroll' => ['status' => 'conflicts', 'conflicts' => [['file' => 'a.php', 'hunks' => ['text']]]]]];
        yield 'a unioned region' => [['reroll' => ['status' => 'clean', 'unioned' => ['a.php:3']]]];
    }

    /**
     * @param array<string, mixed> $result
     */
    #[DataProvider('malformedElements')]
    public function testAMalformedElementFromTheServiceFails(array $result): void
    {
        $this->expectException(TypeError::class);

        PatchRow::fromArray(['package' => 'drupal/webform', 'verdict' => 'conflicts', 'result' => $result], null);
    }

    public function testARowTheReleaseCarriesNothingOfHasNoShippedHunks(): void
    {
        $row = PatchRow::fromArray(['package' => 'drupal/webform', 'verdict' => 'applies'], null);

        $this->assertSame([], $row->hunksShipped);
    }

    public function testCarriesTheFailureModeOfAPatchThatAppliedAndBrokeAFile(): void
    {
        $row = PatchRow::fromArray([
            'package' => 'drupal/domain',
            'verdict' => 'applies',
            'result' => [
                'failure_mode' => 'broken syntax',
                'syntax_errors' => ['src/DomainManager.php: Cannot use A\\C as C because the name is already in use on line 118'],
            ],
        ], null);

        $this->assertSame('applies', $row->verdict);
        $this->assertSame('broken syntax', $row->failureMode());
        $this->assertSame('broken syntax', $row->status());
        $this->assertTrue($row->fails());
        $this->assertTrue($row->needsMention());
        $this->assertCount(1, $row->syntaxErrors);
    }

    public function testAServerThatSendsNoFailureModeLeavesTheRowAsItWas(): void
    {
        $row = PatchRow::fromArray(['package' => 'drupal/webform', 'verdict' => 'applies'], null);

        $this->assertSame('', $row->failureMode());
        $this->assertSame('applies', $row->status());
        $this->assertSame([], $row->syntaxErrors);
        $this->assertFalse($row->fails());
    }

    // 2.x applies with `git apply` alone, so a patch only a lenient apply
    // took is refused there. A failure the service names outranks it.
    public function testAStrictRefusalAsksTheManagerTheRowIsReadUnder(): void
    {
        $fuzzy = ['package' => 'drupal/webform', 'verdict' => 'applies', 'result' => ['fuzzy' => true]];
        $broken = ['package' => 'drupal/webform', 'verdict' => 'applies', 'result' => ['fuzzy' => true, 'failure_mode' => 'broken syntax']];

        self::assertSame(PatchRow::REFUSED_BY_TWO, PatchRow::fromArray($fuzzy, Manager::ofVersion('2.0.0'))->status());
        self::assertSame('applies', PatchRow::fromArray($fuzzy, Manager::ofVersion('1.7.3'))->status());
        self::assertSame('applies', PatchRow::fromArray($fuzzy, null)->status(), 'a row read as the service sent it asks no manager');
        self::assertSame('broken syntax', PatchRow::fromArray($broken, Manager::ofVersion('2.0.0'))->status());
    }

    public function testReadsThePlanTheApiSends(): void
    {
        $plan = self::plan([
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
        self::assertSame(['drupal/domain'], $plan->noRelease());
        self::assertSame(['patchs/local.patch'], $plan->missingFiles);
        self::assertCount(1, $plan->rows());

        $row = $plan->rows()[0];
        self::assertSame('webform', $row->project);
        self::assertSame('6.3.2', $row->version);
        self::assertNotNull($row->reroll);
        self::assertTrue($row->reroll->isClean());
        self::assertTrue($row->reroll->verified);
    }

    public function testTheVerdictTallyIsTheNestedOne(): void
    {
        $plan = self::plan([
            'counts' => ['current' => 30, 'no_release' => 1],
            'plan' => ['counts' => ['applies' => 2]],
        ]);

        self::assertSame(['applies' => 2], $plan->counts());
    }

    public function testAScanWithoutThePatchHalfIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        // A well-formed scan, but the patch half never ran: rendering it
        // would report every patch as fine.
        self::plan(['target_core' => '11.4.5', 'counts' => ['current' => 30], 'rows' => []]);
    }

    // The request carries no title and no path of the site's own, so a row
    // takes both from the declaration it was built from. The service answers
    // one row per declaration, in the order it was sent them.
    public function testARowTakesTheSitesOwnWordsFromItsDeclaration(): void
    {
        $declared = [
            new Declaration('drupal/webform', 'CUP-1383: alias state', 'patchs/alias.patch', 'composer.json', false, []),
            new Declaration('drupal/token', 'Fix the token', 'patchs/token.patch', 'composer.json', false, []),
        ];
        $answer = ['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'verdict' => 'applies'],
            ['package' => 'drupal/token', 'project' => 'token', 'version' => '1.0.0', 'verdict' => 'conflicts'],
        ]]];

        $plan = self::plan($answer, null, $declared);

        self::assertSame(['CUP-1383: alias state', 'Fix the token'], \array_map(static fn ($row) => $row->title, $plan->rows()));
        self::assertSame(['patchs/alias.patch', 'patchs/token.patch'], \array_map(static fn ($row) => $row->source, $plan->rows()));
    }

    // A row the run did not send has no declaration to read.
    public function testARowPastTheDeclarationsIsLeftAsItArrived(): void
    {
        $answer = ['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'title' => 'echoed', 'source' => 'echoed.patch', 'verdict' => 'applies'],
        ]]];

        $plan = self::plan($answer);

        self::assertSame('echoed', $plan->rows()[0]->title);
        self::assertSame('echoed.patch', $plan->rows()[0]->source);
    }

    // The service answers an empty source on purpose, since the request
    // carries none. Reading it would cost the row its file name and the
    // merge request note that hangs off it.
    public function testTheDeclarationWinsOverWhatTheServiceAnswers(): void
    {
        $declared = [new Declaration('drupal/webform', 'from the site', 'site.patch', 'composer.json', false, [])];
        $answer = ['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'title' => 'echoed', 'source' => '', 'verdict' => 'applies'],
        ]]];

        $plan = self::plan($answer, null, $declared);

        self::assertSame('from the site', $plan->rows()[0]->title);
        self::assertSame('site.patch', $plan->rows()[0]->source);
    }

    // A job reads the document `--format=json` prints, which is the answer
    // as received, so the site's own words belong on those rows as well.
    public function testTheDocumentCarriesTheSitesOwnWords(): void
    {
        $declared = [new Declaration('drupal/webform', 'from the site', 'site.patch', 'composer.json', false, [])];
        $answer = ['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'project' => 'webform', 'version' => '6.2.9', 'source' => '', 'verdict' => 'applies'],
        ]]];

        $plan = self::plan($answer, null, $declared);

        self::assertSame('from the site', $plan->document()['plan']['patches'][0]['title']);
        self::assertSame('site.patch', $plan->document()['plan']['patches'][0]['source']);
    }

    public function testAFieldTheServerAddsLaterIsIgnored(): void
    {
        $plan = self::plan([
            'a_field_from_a_later_version' => ['anything'],
            'plan' => [
                'counts' => [],
                'patches' => [['package' => 'drupal/webform', 'verdict' => 'merged', 'confidence' => 0.9]],
                'a_nested_field_from_a_later_version' => 1,
            ],
        ]);

        self::assertCount(1, $plan->rows(), 'an unknown field must not stop an installed plugin working');
    }

    public function testABodyThatIsNotAPlanIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        self::plan(['error' => 'rate limit exceeded']);
    }

    public function testPatchesThatAreNotAListAreRefused(): void
    {
        $this->expectException(RuntimeException::class);

        self::plan(['plan' => ['counts' => [], 'patches' => 'lots']]);
    }

    public function testARowWithoutAPackageIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        self::plan(['plan' => ['patches' => [['title' => 'nameless', 'verdict' => 'merged']]]]);
    }

    // Reroll searches the row's project for an issue, and core's issues live
    // under the project drupal.
    public function testAProjectIsDerivedFromThePackageWhenTheServerOmitsIt(): void
    {
        $plan = self::plan(['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'verdict' => 'merged'],
            ['package' => 'drupal/core', 'verdict' => 'merged'],
        ]]]);

        self::assertSame(['webform', 'drupal'], \array_map(static fn (PatchRow $row): string => $row->project, $plan->rows()));
    }

    public function testAMissingOptionalFieldBecomesADefaultRatherThanAFailure(): void
    {
        $plan = self::plan(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'merged']]]]);

        self::assertSame('', $plan->targetCore);
        self::assertSame('the installed core', $plan->against());
        self::assertSame([], $plan->counts());
        self::assertNull($plan->rows()[0]->reroll);
    }

    public function testAVerdictThisPluginDoesNotKnowIsWork(): void
    {
        $plan = self::plan(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'quarantined']]]]);

        self::assertTrue($plan->rows()[0]->fails());
        self::assertTrue($plan->rows()[0]->needsMention());
    }

    // The selection is an indexed list: a caller reads [0], so dropping
    // the rows before it must renumber rather than leave a gap.
    public function testTheSelectionIsARenumberedList(): void
    {
        $plan = self::plan(['plan' => ['patches' => [
            ['package' => 'drupal/token', 'verdict' => 'applies'],
            ['package' => 'drupal/webform', 'verdict' => 'conflicts'],
            ['package' => 'drupal/domain', 'verdict' => 'merged'],
        ]]]);

        $mention = $plan->worthMentioning();
        self::assertSame([0, 1], \array_keys($mention));
        self::assertSame('drupal/webform', $mention[0]->package);
        self::assertSame('drupal/domain', $mention[1]->package);
    }

    public function testAPatchThatAppliesIsNeitherWorkNorWorthALine(): void
    {
        $plan = self::plan(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'applies']]]]);

        self::assertFalse($plan->rows()[0]->fails());
        self::assertFalse($plan->rows()[0]->needsMention());
    }

    public function testAnApplyingPatchWithAFlaggedCoreReferenceIsWorthALine(): void
    {
        $plan = self::plan(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'applies', 'result' => [
            'core_references' => ['target' => '11.4.5', 'checked' => 1, 'flagged' => [['symbol' => '\\Drupal\\Core\\Gone', 'kind' => 'removed', 'issue' => '\\Drupal\\Core\\Gone was removed in 11.0.0']]],
        ]]]]]);

        self::assertFalse($plan->rows()[0]->fails(), 'the patch applies');
        self::assertTrue($plan->rows()[0]->needsMention(), 'what it references is gone, so say so');
        self::assertSame(1, $plan->rows()[0]->flaggedCoreReferences());
    }

    public function testAShippedPatchIsNoWorkButStillWorthALine(): void
    {
        $plan = self::plan(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'merged']]]]);

        self::assertFalse($plan->rows()[0]->fails(), 'nothing is broken');
        self::assertTrue($plan->rows()[0]->needsMention(), 'the patch can be deleted, so say so');
    }

    public function testARowFallsBackToItsSourceWhenItHasNoTitle(): void
    {
        $plan = self::plan(['plan' => ['patches' => [[
            'package' => 'drupal/webform', 'verdict' => 'merged', 'source' => 'https://www.drupal.org/files/issues/a.patch',
        ]]]]);

        self::assertSame('https://www.drupal.org/files/issues/a.patch', Report::label($plan->rows()[0]));
    }

    public function testTheReasonPrefersTheNoteOverTheError(): void
    {
        $plan = self::plan(['plan' => ['patches' => [[
            'package' => 'drupal/domain', 'verdict' => 'unknown', 'note' => 'no release for 11.4.5',
            'result' => ['error' => 'no repository for domain'],
        ]]]]);

        self::assertSame('no release for 11.4.5', Report::reason($plan->rows()[0]));
    }

    // --package narrows the run: the report, the writes, the fix and the
    // exit code are all about what was named.
    public function testNarrowsToTheNamedPackages(): void
    {
        $only = $this->wholeSite(new Scope(['drupal/webform'], []));

        self::assertSame(['drupal/webform'], self::packagesIn($only));
        self::assertCount(2, $only->rows());
        self::assertSame(['conflicts' => 1, 'applies' => 1], $only->counts(), 'the counts are recomputed from what is left');
        self::assertSame([], $only->noRelease(), 'a package that was not named does not block a scoped run');
        self::assertSame(['drupal/webform', 'drupal/token'], $only->packages(), 'the answer still names every package it has rows for');
    }

    public function testAPackageIsNamedWithOrWithoutTheDrupalPrefix(): void
    {
        self::assertSame(self::packagesIn($this->wholeSite(new Scope(['drupal/webform'], []))), self::packagesIn($this->wholeSite(new Scope(['webform'], []))));
        self::assertSame(['drupal/webform'], self::packagesIn($this->wholeSite(new Scope(['WebForm'], []))));
        self::assertSame(['drupal/webform'], self::packagesIn($this->wholeSite(new Scope(['  webform  '], []))), 'a name typed with spaces is the same name');
    }

    // A caller reads [0], so dropping the rows before it must renumber.
    public function testWhatNarrowingKeepsIsARenumberedList(): void
    {
        $only = $this->wholeSite(new Scope(['token'], []));

        self::assertSame([0], \array_keys($only->rows()));
        self::assertSame('drupal/token', $only->rows()[0]->package);
        self::assertSame([0], \array_keys(($only->document()['plan'] ?? [])['patches'] ?? []));
    }

    public function testTheNarrowedCountsAddUpPerVerdict(): void
    {
        $plan = self::plan(['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'verdict' => 'applies', 'title' => 'a'],
            ['package' => 'drupal/webform', 'verdict' => 'applies', 'title' => 'b'],
            ['package' => 'drupal/token', 'verdict' => 'merged', 'title' => 'c'],
        ]]], new Scope(['webform'], []));

        self::assertSame(['applies' => 2], $plan->counts());
    }

    public function testTheNarrowedBlockedListIsARenumberedList(): void
    {
        $plan = self::plan([
            'rows' => [
                ['package' => 'drupal/autotitle', 'status' => 'no_release'],
                ['package' => 'drupal/domain', 'status' => 'no_release'],
            ],
            'plan' => [
                'patches' => [['package' => 'drupal/domain', 'verdict' => 'unknown', 'title' => 'a']],
            ],
        ], new Scope(['domain'], []));

        self::assertSame([0], \array_keys($plan->noRelease()));
    }

    public function testNarrowingKeepsABlockedPackageThatWasNamed(): void
    {
        $only = $this->wholeSite(new Scope(['domain'], []));

        self::assertSame(['drupal/domain'], $only->noRelease());
    }

    public function testNarrowingToNothingLeavesAPlanWithNoPatches(): void
    {
        self::assertFalse($this->wholeSite(new Scope(['drupal/nothing'], []))->hasPatches());
    }

    public function testTheWholeSiteReadsTheServicesAnswer(): void
    {
        $plan = $this->wholeSite(Scope::whole());

        self::assertCount(3, $plan->rows());
        self::assertSame(['conflicts' => 1, 'applies' => 2], $plan->counts(), 'the service tally stands');
        self::assertArrayNotHasKey('scope', $plan->document());
    }

    // --format=json owes the scope it was asked for.
    public function testNarrowingRewritesWhatJsonWouldPrint(): void
    {
        $raw = $this->wholeSite(new Scope(['webform'], []))->document();
        $nested = $raw['plan'] ?? [];

        self::assertSame(['webform'], $raw['scope']);
        self::assertCount(2, $nested['patches'] ?? []);
        self::assertSame(['conflicts' => 1, 'applies' => 1], $nested['counts'] ?? []);
    }

    // --target latest resolves to a version, and the report says which
    // constraint chose it rather than repeating the word.
    public function testSaysWhichConstraintChoseTheTarget(): void
    {
        $plan = self::plan([
            'target_core' => '11.4.5',
            'target_from' => 'drupal/core-recommended',
            'plan' => ['patches' => []],
        ]);

        self::assertSame('for a move to core 11.4.5 (the newest drupal/core-recommended allows)', $plan->scenario());
        self::assertStringNotContainsString('latest', $plan->scenario());
    }

    public function testANamedTargetSaysNothingAboutAConstraint(): void
    {
        $plan = self::plan(['target_core' => '11.4.5', 'plan' => ['patches' => []]]);

        self::assertSame('for a move to core 11.4.5', $plan->scenario());
    }

    public function testTheMoveStartsFromTheInstalledCoreWhenItIsKnown(): void
    {
        $plan = self::plan(['target_core' => '11.4.5', 'core_installed' => '11.3.12', 'plan' => ['patches' => []]]);

        self::assertSame('for a move from core 11.3.12 to 11.4.5', $plan->scenario());
    }

    public function testAResolvedLatestKeepsItsConstraintAfterBothCores(): void
    {
        $plan = self::plan(['target_core' => '11.4.5', 'core_installed' => '11.3.12', 'target_from' => '^11.3', 'plan' => ['patches' => []]]);

        self::assertSame('for a move from core 11.3.12 to 11.4.5 (the newest ^11.3 allows)', $plan->scenario());
    }

    // A patch is judged against its own package's release. The core
    // version only decides which release that is.
    public function testTheHeadlineNamesTheMoveRatherThanWhatPatchesMeet(): void
    {
        $plan = self::plan(['target_core' => '11.4.5', 'plan' => ['patches' => []]]);

        self::assertStringNotContainsString('against', $plan->scenario());
    }

    public function testARunAgainstTheInstalledCoreSaysSo(): void
    {
        $plan = self::plan(['target_is_installed' => true, 'plan' => ['patches' => []]]);

        self::assertSame('against the releases this site installs', $plan->scenario());
    }

    public function testTheNewVerdictsAreUnderstoodUnchanged(): void
    {
        $plan = self::plan(['plan' => ['patches' => [
            ['package' => 'drupal/a', 'source' => 'a.patch', 'verdict' => 'merged'],
            ['package' => 'drupal/b', 'source' => 'b.patch', 'verdict' => 'applies'],
            ['package' => 'drupal/c', 'source' => 'c.patch', 'verdict' => 'conflicts'],
        ]]]);

        self::assertSame(
            ['merged', 'applies', 'conflicts'],
            \array_map(static fn (PatchRow $row): string => $row->verdict, $plan->rows()),
        );
        self::assertTrue($plan->rows()[0]->isMerged());
    }

    public function testARowSaysWhatDecidedItsRelease(): void
    {
        $plan = self::plan(['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'verdict' => 'applies', 'decided_by' => 'composer'],
            ['package' => 'drupal/token', 'verdict' => 'applies'],
        ]]]);

        self::assertSame('composer', $plan->rows()[0]->decidedBy);
        self::assertSame('', $plan->rows()[1]->decidedBy, 'a row that names no source says nothing');
    }

    public function testEveryOpenRegionIsNamedByItsFileAndIndex(): void
    {
        $reroll = self::rerollOf(['status' => 'conflicts', 'conflicts' => [
            ['file' => 'src/Manager.php', 'regions' => 2],
            ['file' => 'config/services.yml', 'regions' => 1],
        ]]);

        self::assertSame([
            ['file' => 'src/Manager.php', 'region' => 0],
            ['file' => 'src/Manager.php', 'region' => 1],
            ['file' => 'config/services.yml', 'region' => 0],
        ], $reroll->open);
    }

    public function testARegionIsNamedEvenWhenItsTextWasNotSent(): void
    {
        $reroll = self::rerollOf(['status' => 'conflicts', 'conflicts' => [['file' => 'src/Manager.php', 'regions' => 7, 'hunks' => [['line' => 4]]]]]);

        self::assertCount(7, $reroll->open, 'the service caps the text it sends, not the regions it numbers');
        self::assertSame(6, $reroll->open[6]['region']);
    }

    public function testACleanRerollLeavesNoRegion(): void
    {
        $reroll = self::rerollOf(['status' => 'clean', 'patch' => "diff\n"]);

        self::assertSame([], $reroll->open);
    }

    public function testAMergeTheDecisionsSettledLeavesNoRegion(): void
    {
        // The service keeps the conflicts the merge started from and
        // reports the resolved merge clean.
        $reroll = self::rerollOf([
            'status' => 'clean', 'patch' => "diff\n", 'resolutions_applied' => 1,
            'conflicts' => [['file' => 'src/Manager.php', 'regions' => 1]],
        ]);

        self::assertSame([], $reroll->open);
        self::assertSame([], $reroll->removed);
    }

    /**
     * A plan read as the service sent it, through a scope.
     *
     * @param array<mixed>      $decoded
     * @param list<Declaration> $declared
     */
    private static function plan(array $decoded, ?Scope $scope = null, array $declared = []): Plan
    {
        return Plan::fromArray($decoded, $declared, null, $scope ?? Scope::whole());
    }

    /**
     * The re-roll one conflicting row carries.
     *
     * @param array<string, mixed> $reroll
     */
    private static function rerollOf(array $reroll): Reroll
    {
        $read = self::plan(['plan' => ['patches' => [[
            'package' => 'drupal/webform', 'title' => 'Fix a', 'verdict' => 'conflicts',
            'result' => ['reroll' => $reroll],
        ]]]])->rows()[0]->reroll;
        self::assertNotNull($read);

        return $read;
    }

    /**
     * The packages of the rows a plan reads, in plan order.
     *
     * @return list<string>
     */
    private static function packagesIn(Plan $plan): array
    {
        return \array_values(\array_unique(\array_map(static fn (PatchRow $row): string => $row->package, $plan->rows())));
    }

    private function wholeSite(Scope $scope): Plan
    {
        return self::plan([
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
        ], $scope);
    }
}
