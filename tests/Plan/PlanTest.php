<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Plan;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Plan\InvalidPlan;
use Tresbien\Drupatch\Plan\Plan;

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
}
