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
            'counts' => ['needs-reroll' => 1],
            'package_counts' => ['current' => 30],
            'no_release' => ['drupal/domain'],
            'missing_files' => ['patchs/local.patch'],
            'patches' => [[
                'package' => 'drupal/webform', 'project' => 'webform', 'installed' => '6.2.9', 'version' => '6.3.2',
                'title' => 'Fix a', 'source' => 'patches/a.patch', 'verdict' => 'needs-reroll',
                'result' => ['tag' => '6.3.2', 'reroll' => ['status' => 'clean', 'patch' => "diff\n", 'verified' => true]],
            ]],
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

    public function testAFieldTheServerAddsLaterIsIgnored(): void
    {
        $plan = Plan::fromArray([
            'counts' => [],
            'patches' => [['package' => 'drupal/webform', 'verdict' => 'shipped', 'confidence' => 0.9]],
            'a_field_from_a_later_version' => ['anything'],
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

        Plan::fromArray(['counts' => [], 'patches' => 'lots']);
    }

    public function testARowWithoutAPackageIsRefused(): void
    {
        $this->expectException(InvalidPlan::class);

        Plan::fromArray(['patches' => [['title' => 'nameless', 'verdict' => 'shipped']]]);
    }

    public function testAProjectIsDerivedFromThePackageWhenTheServerOmitsIt(): void
    {
        $plan = Plan::fromArray(['patches' => [['package' => 'drupal/webform', 'verdict' => 'shipped']]]);

        self::assertSame('webform', $plan->patches[0]->project);
    }

    public function testAMissingOptionalFieldBecomesADefaultRatherThanAFailure(): void
    {
        $plan = Plan::fromArray(['patches' => [['package' => 'drupal/webform', 'verdict' => 'shipped']]]);

        self::assertSame('', $plan->targetCore);
        self::assertSame('the installed core', $plan->against());
        self::assertSame([], $plan->counts);
        self::assertNull($plan->patches[0]->reroll);
    }

    public function testAFieldOfTheWrongTypeIsTreatedAsAbsent(): void
    {
        $plan = Plan::fromArray([
            'counts' => ['needs-reroll' => 'many', 'unknown' => 2],
            'no_release' => ['drupal/domain', 42],
            'patches' => [],
        ]);

        self::assertSame(['unknown' => 2], $plan->counts);
        self::assertSame(['drupal/domain'], $plan->noRelease);
    }

    public function testAVerdictThisPluginDoesNotKnowIsWork(): void
    {
        $plan = Plan::fromArray(['patches' => [['package' => 'drupal/webform', 'verdict' => 'quarantined']]]);

        self::assertTrue($plan->patches[0]->needsAction());
        self::assertTrue($plan->patches[0]->needsMention());
        self::assertCount(1, $plan->needingAction());
    }

    public function testAPatchThatAppliesIsNeitherWorkNorWorthALine(): void
    {
        $plan = Plan::fromArray(['patches' => [['package' => 'drupal/webform', 'verdict' => 'still-needed']]]);

        self::assertFalse($plan->patches[0]->needsAction());
        self::assertFalse($plan->patches[0]->needsMention());
    }

    public function testAShippedPatchIsNoWorkButStillWorthALine(): void
    {
        $plan = Plan::fromArray(['patches' => [['package' => 'drupal/webform', 'verdict' => 'shipped']]]);

        self::assertFalse($plan->patches[0]->needsAction(), 'nothing is broken');
        self::assertTrue($plan->patches[0]->needsMention(), 'the patch can be deleted, so say so');
    }

    public function testARowFallsBackToItsSourceWhenItHasNoTitle(): void
    {
        $plan = Plan::fromArray(['patches' => [[
            'package' => 'drupal/webform', 'verdict' => 'shipped', 'source' => 'https://www.drupal.org/files/issues/a.patch',
        ]]]);

        self::assertSame('https://www.drupal.org/files/issues/a.patch', $plan->patches[0]->label());
    }

    public function testTheReasonPrefersTheNoteOverTheError(): void
    {
        $plan = Plan::fromArray(['patches' => [[
            'package' => 'drupal/domain', 'verdict' => 'unknown', 'note' => 'no release for 11.4.5',
            'result' => ['error' => 'no repository for domain'],
        ]]]);

        self::assertSame('no release for 11.4.5', $plan->patches[0]->reason());
    }
}
