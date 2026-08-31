<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Render\Report;
use TresBienTech\Drupatch\Tests\PlanFactory;

final class SummaryTest extends TestCase
{
    use PlanFactory;

    private function plan(): Plan
    {
        return $this->planFrom([
            'target_core' => '11.4.5',
            'no_release' => ['drupal/autotitle'],
            'patches' => [
                $this->row(['package' => 'drupal/webform', 'verdict' => 'conflicts', 'title' => 'a']),
                $this->row(['package' => 'drupal/webform', 'verdict' => 'applies', 'title' => 'b']),
                $this->row(['package' => 'drupal/token', 'verdict' => 'merged', 'title' => 'c']),
                $this->row(['package' => 'drupal/autotitle', 'verdict' => 'unknown', 'title' => 'd']),
            ],
        ]);
    }

    public function testNamesThePackagesBehindEachFinding(): void
    {
        $summary = Report::summary($this->plan());

        self::assertSame(['drupal/webform'], $summary['conflicts']);
        self::assertSame(['drupal/autotitle'], $summary['unclear']);
        self::assertSame(['drupal/token'], $summary['merged']);
        self::assertSame(['drupal/autotitle'], $summary['blocked']);
    }

    // A dashboard quotes one or the other, so they have to agree.
    public function testTheCountsAddUpToTheRows(): void
    {
        $plan = $this->plan();

        $counts = Report::summary($plan)['counts'] ?? [];

        self::assertSame(\count($plan->patches), \array_sum($counts));
        self::assertSame(['applies' => 1, 'conflicts' => 1, 'merged' => 1, 'unknown' => 1], $counts);
    }

    public function testAPackageIsNamedOnceHoweverManyRowsItHas(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/webform', 'verdict' => 'conflicts', 'title' => 'a']),
            $this->row(['package' => 'drupal/webform', 'verdict' => 'conflicts', 'title' => 'b']),
        ]]);

        self::assertSame(['drupal/webform'], Report::summary($plan)['conflicts']);
    }

    public function testCarriesTheExitCodeTheRunWillUse(): void
    {
        $plan = $this->plan();

        self::assertSame($plan->exitCode(), Report::summary($plan)['exit_code']);
        self::assertSame($plan->exitCode(true), Report::summary($plan, true)['exit_code']);
    }

    // Strict is off unless asked for, and a plan whose only findings are
    // tolerable is where that shows.
    public function testStrictIsOffByDefault(): void
    {
        $plan = $this->planFrom([
            'no_release' => ['drupal/autotitle'],
            'patches' => [$this->row(['verdict' => 'unknown'])],
        ]);

        self::assertSame(Plan::CLEAN, Report::summary($plan)['exit_code']);
        self::assertSame(Plan::ACTION_NEEDED, Report::summary($plan, true)['exit_code']);
    }

    public function testSaysWhatTheRunWasAbout(): void
    {
        $summary = Report::summary($this->plan());

        self::assertSame('11.4.5', $summary['target_core']);
        self::assertFalse($summary['target_is_installed']);

        $bare = Report::summary($this->planFrom(['target_core' => '', 'target_is_installed' => true]));
        self::assertSame('', $bare['target_core']);
        self::assertTrue($bare['target_is_installed']);
    }

    public function testCountsEveryRowOfAVerdictNotJustTheFirst(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['verdict' => 'applies', 'title' => 'a']),
            $this->row(['verdict' => 'applies', 'title' => 'b']),
            $this->row(['verdict' => 'applies', 'title' => 'c']),
        ]]);

        self::assertSame(['applies' => 3], Report::summary($plan)['counts'] ?? []);
    }

    public function testSaysWhichConstraintChoseTheTarget(): void
    {
        $resolved = Plan::fromArray([
            'target_core' => '11.4.5',
            'target_from' => 'drupal/core-recommended',
            'plan' => ['patches' => []],
        ]);

        self::assertSame('drupal/core-recommended', Report::summary($resolved)['target_from']);
        self::assertArrayNotHasKey('target_from', Report::summary($this->plan()), 'a named target chose itself');
    }

    public function testNarrowingNarrowsTheSummaryWithEverythingElse(): void
    {
        $summary = Report::summary($this->plan()->onlyPackages(['token']));

        self::assertSame(['merged' => 1], $summary['counts']);
        self::assertSame([], $summary['conflicts']);
        self::assertSame([], $summary['blocked'], 'a package that was not named does not block a scoped run');
        self::assertSame(Plan::CLEAN, $summary['exit_code']);
    }

    // A reader has to be able to tell an answer composer made from one
    // the service's release table made.
    public function testCountsWhatDecidedEachRow(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/a', 'decided_by' => 'composer']),
            $this->row(['package' => 'drupal/b', 'decided_by' => 'composer']),
            $this->row(['package' => 'drupal/c', 'decided_by' => 'bundle']),
        ]]);

        self::assertSame(['bundle' => 1, 'composer' => 2], Report::summary($plan)['decided_by'] ?? []);
    }

    public function testSaysNothingAboutSourcesWhenNoRowNamesOne(): void
    {
        self::assertSame([], Report::summary($this->plan())['decided_by'] ?? []);
    }

    public function testASiteWithNothingToSayStillCarriesAShape(): void
    {
        $summary = Report::summary($this->planFrom());

        self::assertSame([], $summary['counts']);
        self::assertSame([], $summary['conflicts']);
        self::assertSame(Plan::CLEAN, $summary['exit_code']);
    }

    // A scheduled job reads this, so the report's layout must never
    // reach it. A key added here is a contract change, not a rendering
    // choice.
    public function testTheSummaryCarriesTheseKeysAndNoOthers(): void
    {
        self::assertSame(
            [
                'target_core',
                'target_is_installed',
                'counts',
                'conflicts',
                'unclear',
                'merged',
                'blocked',
                'decided_by',
                'exit_code',
            ],
            \array_keys(Report::summary($this->planFrom())),
        );
    }

    public function testNoSummaryValueCarriesAMarkOrAnEllipsis(): void
    {
        $encoded = (string) \json_encode(Report::summary($this->plan()));

        foreach (['!', '?', '·', '✓', '…', 'Next:'] as $decoration) {
            self::assertStringNotContainsString($decoration, $encoded, 'the report\'s decoration reached the summary');
        }
    }
}
