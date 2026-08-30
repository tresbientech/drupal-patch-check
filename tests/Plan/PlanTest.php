<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Plan\InvalidPlan;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Plan\Value;

/**
 * The boundary between the server's JSON and the plugin's data.
 */
final class PlanTest extends TestCase
{
    public function testReadsThePlanTheApiSends(): void
    {
        $plan = Plan::fromArray([
            'target_core' => '11.4.5',
            'core_installed' => '10.6.9',
            'bundle_date' => '2026-08-11T02:00:00Z',
            'counts' => ['current' => 30],
            'rows' => [['package' => 'drupal/webform', 'status' => 'current']],
            'plan' => [
                'counts' => ['needs-reroll' => 1],
                'no_release' => ['drupal/domain'],
                'missing_files' => ['patchs/local.patch'],
                'patches' => [[
                    'package' => 'drupal/webform', 'project' => 'webform', 'installed' => '6.2.9', 'version' => '6.3.2',
                    'title' => 'Fix a', 'source' => 'patches/a.patch', 'verdict' => 'needs-reroll',
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
        self::assertTrue($row->reroll->isClean());
        self::assertTrue($row->reroll->verified);
    }

    public function testTheTwoTalliesAreReadFromTheirOwnLevel(): void
    {
        $plan = Plan::fromArray([
            'counts' => ['current' => 30, 'no_release' => 1],
            'plan' => ['counts' => ['still-needed' => 2]],
        ]);

        self::assertSame(['still-needed' => 2], $plan->counts, 'the verdict tally is the nested one');
        self::assertSame(['current' => 30, 'no_release' => 1], $plan->packageCounts);
    }

    public function testAScanWithoutThePatchHalfIsRefused(): void
    {
        $this->expectException(InvalidPlan::class);

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
                'patches' => [['package' => 'drupal/webform', 'verdict' => 'shipped', 'confidence' => 0.9]],
                'a_nested_field_from_a_later_version' => 1,
            ],
        ]);

        self::assertCount(1, $plan->patches, 'an unknown field must not stop an installed plugin working');
    }

    public function testABodyThatIsNotAPlanIsRefused(): void
    {
        $this->expectException(InvalidPlan::class);

        Plan::fromArray(['error' => 'rate limit exceeded']);
    }

    public function testPatchesThatAreNotAListAreRefused(): void
    {
        $this->expectException(InvalidPlan::class);

        Plan::fromArray(['plan' => ['counts' => [], 'patches' => 'lots']]);
    }

    public function testARowWithoutAPackageIsRefused(): void
    {
        $this->expectException(InvalidPlan::class);

        Plan::fromArray(['plan' => ['patches' => [['title' => 'nameless', 'verdict' => 'shipped']]]]);
    }

    public function testAProjectIsDerivedFromThePackageWhenTheServerOmitsIt(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'shipped']]]]);

        self::assertSame('webform', $plan->patches[0]->project);
    }

    public function testAMissingOptionalFieldBecomesADefaultRatherThanAFailure(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'shipped']]]]);

        self::assertSame('', $plan->targetCore);
        self::assertSame('the installed core', $plan->against());
        self::assertSame([], $plan->counts);
        self::assertNull($plan->patches[0]->reroll);
    }

    public function testAFieldOfTheWrongTypeIsTreatedAsAbsent(): void
    {
        $plan = Plan::fromArray(['plan' => [
            'counts' => ['needs-reroll' => 'many', 'unknown' => 2],
            'no_release' => ['drupal/domain', 42],
            'patches' => [],
        ]]);

        self::assertSame(['unknown' => 2], $plan->counts);
        self::assertSame(['drupal/domain'], $plan->noRelease);
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
            ['package' => 'drupal/token', 'verdict' => 'still-needed'],
            ['package' => 'drupal/webform', 'verdict' => 'needs-reroll'],
            ['package' => 'drupal/domain', 'verdict' => 'shipped'],
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
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'still-needed']]]]);

        self::assertFalse($plan->patches[0]->needsAction());
        self::assertFalse($plan->patches[0]->needsMention());
    }

    public function testAShippedPatchIsNoWorkButStillWorthALine(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [['package' => 'drupal/webform', 'verdict' => 'shipped']]]]);

        self::assertFalse($plan->patches[0]->needsAction(), 'nothing is broken');
        self::assertTrue($plan->patches[0]->needsMention(), 'the patch can be deleted, so say so');
    }

    public function testARowFallsBackToItsSourceWhenItHasNoTitle(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [[
            'package' => 'drupal/webform', 'verdict' => 'shipped', 'source' => 'https://www.drupal.org/files/issues/a.patch',
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

        $only = $plan->onlyPackages(['drupal/webform']);

        self::assertSame(['drupal/webform'], $only->packages());
        self::assertCount(2, $only->patches);
        self::assertSame(['needs-reroll' => 1, 'still-needed' => 1], $only->counts, 'the counts are recomputed from what is left');
        self::assertSame([], $only->packageCounts, 'a scoped run quotes no site-wide package tally');
        self::assertSame([], $only->noRelease, 'a package that was not named does not block a scoped run');
    }

    public function testAPackageIsNamedWithOrWithoutTheDrupalPrefix(): void
    {
        $plan = $this->wholeSite();

        self::assertSame($plan->onlyPackages(['drupal/webform'])->packages(), $plan->onlyPackages(['webform'])->packages());
        self::assertSame(['drupal/webform'], $plan->onlyPackages(['WebForm'])->packages());
        self::assertSame(['drupal/webform'], $plan->onlyPackages(['  webform  '])->packages(), 'a name typed with spaces is the same name');
    }

    // A caller reads [0], so dropping the rows before it must renumber.
    public function testWhatNarrowingKeepsIsARenumberedList(): void
    {
        $only = $this->wholeSite()->onlyPackages(['token']);

        self::assertSame([0], \array_keys($only->patches));
        self::assertSame('drupal/token', $only->patches[0]->package);
        self::assertSame([0], \array_keys(Value::objects(Value::keyed(Value::object($only->raw, 'plan')), 'patches')));
    }

    public function testTheNarrowedCountsAddUpPerVerdict(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'verdict' => 'still-needed', 'title' => 'a'],
            ['package' => 'drupal/webform', 'verdict' => 'still-needed', 'title' => 'b'],
            ['package' => 'drupal/token', 'verdict' => 'shipped', 'title' => 'c'],
        ]]]);

        self::assertSame(['still-needed' => 2], $plan->onlyPackages(['webform'])->counts);
    }

    public function testTheNarrowedBlockedListIsARenumberedList(): void
    {
        $plan = Plan::fromArray([
            'plan' => [
                'no_release' => ['drupal/autotitle', 'drupal/domain'],
                'patches' => [['package' => 'drupal/domain', 'verdict' => 'unknown', 'title' => 'a']],
            ],
        ]);

        self::assertSame([0], \array_keys($plan->onlyPackages(['domain'])->noRelease));
    }

    public function testNarrowingKeepsABlockedPackageThatWasNamed(): void
    {
        $only = $this->wholeSite()->onlyPackages(['domain']);

        self::assertSame(['drupal/domain'], $only->noRelease);
        self::assertTrue($only->isBlocked());
    }

    public function testNarrowingToNothingLeavesAPlanWithNoPatches(): void
    {
        self::assertFalse($this->wholeSite()->onlyPackages(['drupal/nothing'])->hasPatches());
    }

    public function testAnEmptyPackageListLeavesThePlanAlone(): void
    {
        $plan = $this->wholeSite();

        self::assertSame($plan, $plan->onlyPackages([]));
    }

    // --json owes the scope it was asked for.
    public function testNarrowingRewritesWhatJsonWouldPrint(): void
    {
        $raw = $this->wholeSite()->onlyPackages(['webform'])->raw;
        $nested = Value::keyed(Value::object($raw, 'plan'));

        self::assertSame(['webform'], $raw['scope']);
        self::assertCount(2, Value::objects($nested, 'patches'));
        self::assertSame(['needs-reroll' => 1, 'still-needed' => 1], Value::counts($nested, 'counts'));
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

        self::assertSame('11.4.5 (the newest drupal/core-recommended allows)', $plan->judgedAgainst());
        self::assertStringNotContainsString('latest', $plan->judgedAgainst());
    }

    public function testANamedTargetSaysNothingAboutAConstraint(): void
    {
        $plan = Plan::fromArray(['target_core' => '11.4.5', 'plan' => ['patches' => []]]);

        self::assertSame('11.4.5', $plan->judgedAgainst());
    }

    public function testARowSaysWhatDecidedItsRelease(): void
    {
        $plan = Plan::fromArray(['plan' => ['patches' => [
            ['package' => 'drupal/webform', 'verdict' => 'still-needed', 'decided_by' => 'composer'],
            ['package' => 'drupal/token', 'verdict' => 'still-needed'],
        ]]]);

        self::assertSame('composer', $plan->patches[0]->decidedBy);
        self::assertSame('', $plan->patches[1]->decidedBy, 'a row that names no source says nothing');
    }

    private function wholeSite(): Plan
    {
        return Plan::fromArray([
            'target_core' => '11.4.5',
            'counts' => ['current' => 30, 'no_release' => 1],
            'plan' => [
                'counts' => ['needs-reroll' => 1, 'still-needed' => 2],
                'no_release' => ['drupal/domain'],
                'patches' => [
                    ['package' => 'drupal/webform', 'verdict' => 'needs-reroll', 'title' => 'a'],
                    ['package' => 'drupal/webform', 'verdict' => 'still-needed', 'title' => 'b'],
                    ['package' => 'drupal/token', 'verdict' => 'still-needed', 'title' => 'c'],
                ],
            ],
        ]);
    }
}
