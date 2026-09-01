<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Tests;

use PHPUnit\Framework\TestCase;
use TresBienTech\Drupatch\Plan\Plan;

class OutcomeTest extends TestCase
{
    use PlanFactory;

    public function testEveryPatchApplyingOrShippedIsClean(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['verdict' => 'applies']),
            $this->row(['verdict' => 'merged']),
        ]]);

        self::assertSame(Plan::CLEAN, $plan->exitCode());
    }

    public function testAPatchThatNoLongerAppliesNeedsAction(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'conflicts'])]]);

        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode());
    }

    // A patch the service could not judge is as often a mirror that lags
    // a release as a real problem, and neither is the repository's to fix.
    public function testAPatchThatCouldNotBeJudgedDoesNotFailByItself(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'unknown'])]]);

        self::assertSame(Plan::CLEAN, $plan->exitCode());
        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode(true), 'strict asked to be woken by it');
    }

    // A run that declared patches and checked none proves nothing. Under
    // strict that is worth waking someone for; on its own it is not a
    // finding about the repository.
    public function testARunThatCheckedNothingFailsOnlyUnderStrict(): void
    {
        $plan = $this->planFrom(['patches' => []]);

        self::assertSame(Plan::CLEAN, $plan->exitCode(false, true));
        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode(true, true));
    }

    public function testASiteWithNoPatchesAtAllIsCleanUnderStrict(): void
    {
        $plan = $this->planFrom(['patches' => []]);

        self::assertSame(Plan::CLEAN, $plan->exitCode(true, false));
    }

    public function testAPatchThatWillNotApplyFailsEitherWay(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'conflicts'])]]);

        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode());
        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode(true));
    }

    public function testAVerdictTheServerAddedLaterFailsEitherWay(): void
    {
        $plan = $this->planFrom(['patches' => [$this->row(['verdict' => 'quarantined'])]]);

        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode(), 'only the known verdicts may exit 0');
        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode(true));
    }

    // The exit code is about patches. A blocked package carrying none says
    // nothing about them, and a blocked package whose patches were judged
    // has already had its say through their verdicts.
    public function testABlockedPackageCarryingNoPatchIsCleanUnderStrict(): void
    {
        $plan = $this->planFrom(['no_release' => ['drupal/domain']]);

        self::assertSame(Plan::CLEAN, $plan->exitCode());
        self::assertSame(Plan::CLEAN, $plan->exitCode(true));
    }

    public function testABlockedPackageWhosePatchesWereJudgedIsCleanUnderStrict(): void
    {
        $plan = $this->planFrom([
            'no_release' => ['drupal/select2'],
            'patches' => [
                $this->row(['package' => 'drupal/select2', 'verdict' => 'applies']),
                $this->row(['package' => 'drupal/select2', 'verdict' => 'applies']),
            ],
        ]);

        self::assertSame(Plan::CLEAN, $plan->exitCode());
        self::assertSame(Plan::CLEAN, $plan->exitCode(true));
    }

    // Blocking cost a real answer here, and the unclear row is what says so.
    public function testABlockedPackageWithAnUnclearRowStillFailsAStrictRun(): void
    {
        $plan = $this->planFrom([
            'no_release' => ['drupal/domain'],
            'patches' => [$this->row(['package' => 'drupal/domain', 'verdict' => 'unknown'])],
        ]);

        self::assertSame(Plan::CLEAN, $plan->exitCode());
        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode(true));
    }

    public function testEverythingTolerableTogetherIsStillClean(): void
    {
        $plan = $this->planFrom([
            'no_release' => ['drupal/domain'],
            'patches' => [
                $this->row(['verdict' => 'applies']),
                $this->row(['verdict' => 'merged']),
                $this->row(['verdict' => 'unknown']),
            ],
        ]);

        self::assertSame(Plan::CLEAN, $plan->exitCode());
        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode(true));
    }

    // The scope decides what the exit code is about.
    public function testNarrowingDecidesWhatTheExitCodeIsAbout(): void
    {
        $plan = $this->planFrom(['patches' => [
            $this->row(['package' => 'drupal/token', 'verdict' => 'applies']),
            $this->row(['package' => 'drupal/webform', 'verdict' => 'conflicts']),
        ]]);

        self::assertSame(Plan::ACTION_NEEDED, $plan->exitCode());
        self::assertSame(Plan::CLEAN, $plan->onlyPackages(['token'])->exitCode());
        self::assertSame(Plan::ACTION_NEEDED, $plan->onlyPackages(['webform'])->exitCode());
    }

    public function testASiteWithNothingToSayIsClean(): void
    {
        self::assertSame(Plan::CLEAN, $this->planFrom([])->exitCode());
    }

    public function testAFlaggedCoreReferenceLeavesTheExitCodeAlone(): void
    {
        $plan = $this->planFrom(['counts' => ['applies' => 1], 'patches' => [$this->row([
            'verdict' => 'applies',
            'result' => ['core_references' => ['target' => '11.4.5', 'checked' => 1, 'flagged' => [
                ['symbol' => '\\Drupal\\Core\\Gone', 'kind' => 'removed', 'issue' => '\\Drupal\\Core\\Gone was removed in 11.0.0'],
            ]]],
        ])]]);

        self::assertSame(Plan::CLEAN, $plan->exitCode());
        self::assertSame(Plan::CLEAN, $plan->exitCode(true));
    }
}
