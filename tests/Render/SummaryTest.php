<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Tests\Render;

use PHPUnit\Framework\TestCase;
use Tresbien\Drupatch\Outcome;
use Tresbien\Drupatch\Plan\Plan;
use Tresbien\Drupatch\Plan\Value;
use Tresbien\Drupatch\Render\Summary;
use Tresbien\Drupatch\Tests\PlanFactory;

final class SummaryTest extends TestCase
{
    use PlanFactory;

    private function plan(): Plan
    {
        return $this->planFrom([
            'target_core' => '11.4.5',
            'no_release' => ['drupal/autotitle'],
            'patches' => [
                $this->row(['package' => 'drupal/webform', 'verdict' => 'needs-reroll', 'title' => 'a']),
                $this->row(['package' => 'drupal/webform', 'verdict' => 'still-needed', 'title' => 'b']),
                $this->row(['package' => 'drupal/token', 'verdict' => 'shipped', 'title' => 'c']),
                $this->row(['package' => 'drupal/autotitle', 'verdict' => 'unknown', 'title' => 'd']),
            ],
        ]);
    }

    public function testNamesThePackagesBehindEachFinding(): void
    {
        $summary = Summary::of($this->plan());

        self::assertSame(['drupal/webform'], $summary['needs_reroll']);
        self::assertSame(['drupal/autotitle'], $summary['unclear']);
        self::assertSame(['drupal/token'], $summary['shipped']);
        self::assertSame(['drupal/autotitle'], $summary['blocked']);
    }

    // A dashboard quotes one or the other, so they have to agree.
    public function testTheCountsAddUpToTheRows(): void
    {
        $plan = $this->plan();

        $counts = Value::counts(Summary::of($plan), 'counts');

        self::assertSame(\count($plan->patches), \array_sum($counts));
        self::assertSame(['needs-reroll' => 1, 'shipped' => 1, 'still-needed' => 1, 'unknown' => 1], $counts);
    }

    public function testAPackageIsNamedOnceHoweverManyRowsItHas(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/webform', 'verdict' => 'needs-reroll', 'title' => 'a']),
            $this->row(['package' => 'drupal/webform', 'verdict' => 'needs-reroll', 'title' => 'b']),
        ]]);

        self::assertSame(['drupal/webform'], Summary::of($plan)['needs_reroll']);
    }

    public function testCarriesTheExitCodeTheRunWillUse(): void
    {
        $plan = $this->plan();

        self::assertSame(Outcome::of($plan), Summary::of($plan)['exit_code']);
        self::assertSame(Outcome::of($plan, true), Summary::of($plan, true)['exit_code']);
    }

    // Strict is off unless asked for, and a plan whose only findings are
    // tolerable is where that shows.
    public function testStrictIsOffByDefault(): void
    {
        $plan = $this->planFrom([
            'no_release' => ['drupal/autotitle'],
            'patches' => [$this->row(['verdict' => 'unknown'])],
        ]);

        self::assertSame(Outcome::CLEAN, Summary::of($plan)['exit_code']);
        self::assertSame(Outcome::ACTION_NEEDED, Summary::of($plan, true)['exit_code']);
    }

    public function testSaysWhatTheRunWasAbout(): void
    {
        $summary = Summary::of($this->plan());

        self::assertSame('11.4.5', $summary['target_core']);
        self::assertFalse($summary['target_is_installed']);

        $bare = Summary::of($this->planFrom(['target_core' => '', 'target_is_installed' => true]));
        self::assertSame('', $bare['target_core']);
        self::assertTrue($bare['target_is_installed']);
    }

    public function testCountsEveryRowOfAVerdictNotJustTheFirst(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['verdict' => 'still-needed', 'title' => 'a']),
            $this->row(['verdict' => 'still-needed', 'title' => 'b']),
            $this->row(['verdict' => 'still-needed', 'title' => 'c']),
        ]]);

        self::assertSame(['still-needed' => 3], Value::counts(Summary::of($plan), 'counts'));
    }

    public function testSaysWhichConstraintChoseTheTarget(): void
    {
        $resolved = Plan::fromArray([
            'target_core' => '11.4.5',
            'target_from' => 'drupal/core-recommended',
            'plan' => ['patches' => []],
        ]);

        self::assertSame('drupal/core-recommended', Summary::of($resolved)['target_from']);
        self::assertArrayNotHasKey('target_from', Summary::of($this->plan()), 'a named target chose itself');
    }

    public function testNarrowingNarrowsTheSummaryWithEverythingElse(): void
    {
        $summary = Summary::of($this->plan()->onlyPackages(['token']));

        self::assertSame(['shipped' => 1], $summary['counts']);
        self::assertSame([], $summary['needs_reroll']);
        self::assertSame([], $summary['blocked'], 'a package that was not named does not block a scoped run');
        self::assertSame(Outcome::CLEAN, $summary['exit_code']);
    }

    public function testASiteWithNothingToSayStillCarriesAShape(): void
    {
        $summary = Summary::of($this->planFrom());

        self::assertSame([], $summary['counts']);
        self::assertSame([], $summary['needs_reroll']);
        self::assertSame(Outcome::CLEAN, $summary['exit_code']);
    }
}
